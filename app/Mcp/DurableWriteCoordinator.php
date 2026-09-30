<?php

namespace App\Mcp;

use App\Http\ApiErrorException;
use App\Support\JsonCanonicalizer;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Mcp\Exceptions\JsonRpcException;
use RuntimeException;
use Throwable;

final readonly class DurableWriteCoordinator
{
    public const int LEASE_SECONDS = 300;

    public function __construct(private WriteFaultInjector $faults) {}

    /**
     * @param  array<string, mixed>  $intent
     * @param  Closure(string, string): array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    public function run(
        OwnerSubject $owner,
        string $tool,
        string $idempotencyKey,
        array $intent,
        string $targetType,
        Closure $operation,
    ): array {
        $intentHash = hash('sha256', JsonCanonicalizer::encode($intent));
        $candidateId = (string) Str::uuid();
        $candidateTarget = (string) Str::uuid();
        $fence = (string) Str::uuid();
        $now = CarbonImmutable::now();

        try {
            $this->faults->beforeClaim($tool);
            $acquired = DB::transaction(function () use ($owner, $tool, $idempotencyKey, $intentHash, $targetType, $candidateId, $candidateTarget, $fence, $now): array {
                $inserted = DB::table('mcp_write_claims')->insertOrIgnore([
                    'id' => $candidateId,
                    'owner_subject_type' => $owner->type,
                    'owner_subject_ref' => $owner->ref,
                    'tool' => $tool,
                    'idempotency_key' => $idempotencyKey,
                    'intent_hash' => $intentHash,
                    'target_type' => $targetType,
                    'target_id' => $candidateTarget,
                    'state' => 'in_progress',
                    'fence_token' => $fence,
                    'lease_expires_at' => $now->addSeconds(self::LEASE_SECONDS),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]) === 1;

                $claim = DB::table('mcp_write_claims')
                    ->where('owner_subject_type', $owner->type)
                    ->where('owner_subject_ref', $owner->ref)
                    ->where('tool', $tool)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($claim === null) {
                    throw new JsonRpcException('The write service is unavailable.', -32003);
                }

                if (! hash_equals((string) $claim->intent_hash, $intentHash)) {
                    return ['response' => $this->outcome('refused', ['reason' => 'idempotency_key_reused']), 'execute' => false];
                }

                $effect = DB::table('mcp_write_effects')->where('claim_id', $claim->id)->first();

                if ($effect !== null) {
                    $response = $this->decodeResponse($effect->accepted_response);
                    DB::table('mcp_write_claims')->where('id', $claim->id)->update([
                        'state' => 'accepted',
                        'terminal_response' => json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                        'fence_token' => null,
                        'lease_expires_at' => null,
                        'updated_at' => $now,
                    ]);
                    $response['outcome'] = $inserted ? 'accepted' : 'already_accepted';

                    return ['response' => $response, 'execute' => false];
                }

                if (! $inserted) {
                    if ($claim->state === 'accepted') {
                        $response = $this->decodeResponse($claim->terminal_response);
                        $response['outcome'] = 'already_accepted';

                        return ['response' => $response, 'execute' => false];
                    }

                    if (in_array($claim->state, ['refused', 'outcome_unknown'], true)) {
                        return ['response' => $this->decodeResponse($claim->terminal_response), 'execute' => false];
                    }

                    if ($claim->refusal_response !== null) {
                        $response = $this->decodeResponse($claim->refusal_response);
                        DB::table('mcp_write_claims')->where('id', $claim->id)->update([
                            'state' => 'refused',
                            'terminal_response' => json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                            'fence_token' => null,
                            'lease_expires_at' => null,
                            'updated_at' => $now,
                        ]);

                        return ['response' => $response, 'execute' => false];
                    }

                    if ($claim->lease_expires_at !== null && CarbonImmutable::parse($claim->lease_expires_at)->isPast()) {
                        $response = $this->outcome('outcome_unknown');
                        DB::table('mcp_write_claims')->where('id', $claim->id)->update([
                            'state' => 'outcome_unknown',
                            'terminal_response' => json_encode($response, JSON_THROW_ON_ERROR),
                            'fence_token' => null,
                            'lease_expires_at' => null,
                            'updated_at' => $now,
                        ]);

                        return ['response' => $response, 'execute' => false];
                    }

                    return ['response' => $this->outcome('in_progress'), 'execute' => false];
                }

                return [
                    'execute' => true,
                    'claim_id' => (string) $claim->id,
                    'target_id' => (string) $claim->target_id,
                    'fence_token' => (string) $claim->fence_token,
                ];
            }, 3);
        } catch (JsonRpcException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new JsonRpcException('The write service is unavailable.', -32003);
        }

        if ($acquired['execute'] === false) {
            /** @var array<string, mixed> */
            return $acquired['response'];
        }

        $claimId = (string) $acquired['claim_id'];
        $targetId = (string) $acquired['target_id'];
        $fenceToken = (string) $acquired['fence_token'];

        try {
            $this->faults->beforeOperation($tool);
            $result = DB::transaction(function () use ($operation, $claimId, $targetId, $fenceToken): array {
                $claim = DB::table('mcp_write_claims')->where('id', $claimId)->lockForUpdate()->first();

                if ($claim === null
                    || $claim->state !== 'in_progress'
                    || ! is_string($claim->fence_token)
                    || ! hash_equals($claim->fence_token, $fenceToken)
                    || $claim->lease_expires_at === null
                    || CarbonImmutable::parse($claim->lease_expires_at)->isPast()) {
                    throw new RuntimeException('The write claim no longer owns its effect fence.');
                }

                try {
                    $response = DB::transaction(fn (): array => $operation($targetId, $claimId));
                } catch (ApiErrorException $exception) {
                    $response = $this->outcome('refused', ['reason' => $exception->errorCode]);
                    $updated = DB::table('mcp_write_claims')
                        ->where('id', $claimId)
                        ->where('fence_token', $fenceToken)
                        ->where('state', 'in_progress')
                        ->update([
                            'refusal_response' => json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                            'updated_at' => now(),
                        ]);

                    if ($updated !== 1) {
                        throw new RuntimeException('The write claim no longer owns its refusal fence.');
                    }

                    return ['accepted' => false, 'response' => $response];
                }

                $response['outcome'] = 'accepted';
                DB::table('mcp_write_effects')->insert([
                    'claim_id' => $claimId,
                    'accepted_response' => json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'created_at' => now(),
                ]);

                return ['accepted' => true, 'response' => $response];
            }, 3);

            /** @var array<string, mixed> $response */
            $response = $result['response'];

            if ($result['accepted'] === false) {
                return $this->settleFailure($claimId, $fenceToken, $tool, 'refused', $response);
            }

            $this->faults->beforeSettlement($tool);
            $this->settleAccepted($claimId, $fenceToken, $response);

            return $response;
        } catch (ApiErrorException $exception) {
            return $this->settleFailure(
                $claimId,
                $fenceToken,
                $tool,
                'refused',
                $this->outcome('refused', ['reason' => $exception->errorCode]),
            );
        } catch (Throwable) {
            $effect = DB::table('mcp_write_effects')->where('claim_id', $claimId)->first();

            if ($effect !== null) {
                $accepted = $this->decodeResponse($effect->accepted_response);

                try {
                    $this->faults->beforeAcceptedRecoverySettlement($tool);
                    $this->settleAccepted($claimId, $fenceToken, $accepted);
                } catch (Throwable) {
                    // The accepted effect marker remains the replay authority.
                }

                return $accepted;
            }

            return $this->settleFailure(
                $claimId,
                $fenceToken,
                $tool,
                'outcome_unknown',
                $this->outcome('outcome_unknown'),
            );
        }
    }

    /** @param array<string, mixed> $response */
    private function settleAccepted(string $claimId, string $fenceToken, array $response): void
    {
        $updated = DB::table('mcp_write_claims')
            ->where('id', $claimId)
            ->where('fence_token', $fenceToken)
            ->where('state', 'in_progress')
            ->update([
                'state' => 'accepted',
                'terminal_response' => json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'fence_token' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            $known = DB::table('mcp_write_claims')->where('id', $claimId)->first();

            if ($known === null || $known->state !== 'accepted') {
                throw new QueryException('', '', [], new RuntimeException('Claim settlement lost its fence.'));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function settleFailure(
        string $claimId,
        string $fenceToken,
        string $tool,
        string $state,
        array $response,
    ): array {
        try {
            $this->faults->beforeFailureSettlement($tool);
            $updated = DB::table('mcp_write_claims')
                ->where('id', $claimId)
                ->where('fence_token', $fenceToken)
                ->where('state', 'in_progress')
                ->update([
                    'state' => $state,
                    'terminal_response' => json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'fence_token' => null,
                    'lease_expires_at' => null,
                    'updated_at' => now(),
                ]);

            if ($updated === 1) {
                return $response;
            }

            $known = DB::table('mcp_write_claims')->where('id', $claimId)->first();

            if (in_array($known?->state, ['accepted', 'refused', 'outcome_unknown'], true)) {
                return $this->decodeResponse($known->terminal_response);
            }
        } catch (Throwable) {
            // The finite live lease remains the durable non-terminal result.
        }

        return $this->outcome('in_progress');
    }

    /** @return array<string, mixed> */
    private function decodeResponse(mixed $response): array
    {
        $decoded = json_decode((string) $response, true);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : $this->outcome('outcome_unknown');
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function outcome(string $outcome, array $extra = []): array
    {
        return ['outcome' => $outcome, ...$extra];
    }
}
