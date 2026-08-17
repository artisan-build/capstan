<?php

namespace App\Http\Controllers\Api;

use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Features\Postmaster;
use App\Http\ApiError;
use App\Http\ApiErrorException;
use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Models\Inbox;
use App\Models\Spoke;
use App\Models\SpokeProbe;
use App\Models\User;
use App\Postmaster\ProbeFailureNotifier;
use App\Postmaster\ProbeManager;
use App\Support\Address;
use App\Support\EnvelopeSigner;
use App\Support\ServerIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use JsonException;
use Laravel\Pennant\Feature;
use RuntimeException;
use stdClass;
use Throwable;

class PollController extends Controller
{
    /** Request caps. Documented in docs/postmaster.md; keep both in sync. */
    public const int MAX_READY_INBOXES = 256;

    public const int MAX_ACKS = 1000;

    public const int MAX_OUTBOUND = 100;

    /** Bind-parameter defence in depth: never let one statement carry an unbounded IN list. */
    private const int QUERY_CHUNK = 500;

    public function __invoke(
        Request $request,
        EnvelopeSigner $signer,
        ServerIdentity $identity,
        ProbeManager $probeManager,
        ProbeFailureNotifier $probeFailureNotifier,
    ): JsonResponse {
        if (! Feature::active(Postmaster::class)) {
            return ApiError::notFound();
        }

        $payload = json_decode($request->getContent(), false);

        if (! $payload instanceof stdClass || json_last_error() !== JSON_ERROR_NONE) {
            return $this->validationError(['body' => ['The request body must be a JSON object.']]);
        }

        $validation = $this->validatePayload($payload);

        if ($validation instanceof JsonResponse) {
            return $validation;
        }

        $envelopes = [];

        foreach ($validation['outbound'] as $index => $wireEnvelope) {
            $envelope = $this->makeEnvelope($wireEnvelope);

            if (! $envelope->isVersionSupported()) {
                return ApiError::response(422, 'unsupported_version', 'The envelope version is not supported.', [
                    'known_version' => Envelope::CURRENT_VERSION,
                    'index' => $index,
                ]);
            }

            try {
                $verified = $signer->verify($envelope);
            } catch (InvalidArgumentException|JsonException) {
                return $this->validationError([
                    "outbound.$index.body" => ['The envelope body contains unsupported JSON values.'],
                ]);
            }

            if (! $verified) {
                return ApiError::response(422, 'invalid_signature', 'The envelope signature is invalid.', [
                    'index' => $index,
                ]);
            }

            $envelopes[] = $envelope;
        }

        /** @var User $user */
        $user = Auth::user();
        $token = $user->currentAccessToken();

        $serverId = $identity->id();

        try {
            /** @var array{payload: array{inbound: list<array<string, mixed>>, cursor: string|null, probe_challenge?: array{probe_id: string, nonce: string, algorithm: string}}, failure: array{Spoke, SpokeProbe}|null} $result */
            $result = DB::transaction(function () use ($user, $token, $validation, $envelopes, $serverId, $probeManager): array {
                $now = now();
                $spoke = $this->resolveSpoke($user->id, (int) $token->getKey(), $now);
                $readyInboxes = array_values(array_unique($validation['ready_inboxes']));
                $failedProbe = $probeManager->respond($spoke, $validation['probe_response'], $now);

                $this->refreshRouting($spoke, $user->id, $readyInboxes, $validation['cursor'], $now);
                $this->assertSendersOwned($user->id, $envelopes, $serverId);

                foreach ($envelopes as $envelope) {
                    $this->storeEnvelope($envelope, $serverId, $now);
                }

                $this->processAcks($user->id, $validation['acks'], $serverId, $now);
                $inbound = $this->inbound($spoke, $user->id, $serverId);
                $this->markDelivered($inbound, $now);
                $challenge = $probeManager->issue($spoke, $now);

                $payload = [
                    'inbound' => $inbound->map(fn (Envelope $envelope): array => $this->wireEnvelope($envelope))->values()->all(),
                    'cursor' => $inbound->last()?->id,
                ];

                if ($challenge !== null) {
                    $payload['probe_challenge'] = $challenge;
                }

                return [
                    'payload' => $payload,
                    'failure' => $failedProbe === null ? null : [$spoke, $failedProbe],
                ];
            });
        } catch (ApiErrorException $rejection) {
            return $rejection->toResponse($request);
        }

        if ($result['failure'] !== null) {
            try {
                $probeFailureNotifier->notify(...$result['failure']);
            } catch (Throwable $exception) {
                Log::error('Postmaster probe failure notification failed.', [
                    'spoke_id' => $result['failure'][0]->id,
                    'probe_id' => $result['failure'][1]->probe_id,
                    'exception' => $exception,
                ]);
            }
        }

        return new JsonResponse($result['payload']);
    }

    /**
     * @return array{ready_inboxes: list<string>, outbound: list<array<string, mixed>>, acks: list<string>, cursor: string|null, probe_response: array{probe_id: string, digest: string}|null}|JsonResponse
     */
    private function validatePayload(stdClass $payload): array|JsonResponse
    {
        $presence = $payload->presence ?? null;
        $rawOutbound = $payload->outbound ?? [];
        $hasProbeResponse = property_exists($payload, 'probe_response');
        $rawProbeResponse = $hasProbeResponse ? $payload->probe_response : null;
        $input = [
            'presence' => $presence instanceof stdClass ? get_object_vars($presence) : $presence,
            'outbound' => is_array($rawOutbound)
                ? array_map(static fn (mixed $item): mixed => $item instanceof stdClass ? get_object_vars($item) : $item, $rawOutbound)
                : $rawOutbound,
            'acks' => $payload->acks ?? [],
            'cursor' => $payload->cursor ?? null,
        ];

        if ($hasProbeResponse) {
            $input['probe_response'] = $rawProbeResponse instanceof stdClass
                ? get_object_vars($rawProbeResponse)
                : $rawProbeResponse;
        }

        // Enforce the caps before the wildcard rules below are expanded: per-element
        // validation of an over-sized array is itself a CPU sink worth tens of seconds.
        $overCap = $this->overCapErrors([
            'presence.ready_inboxes' => [$presence instanceof stdClass ? ($presence->ready_inboxes ?? null) : null, self::MAX_READY_INBOXES],
            'outbound' => [$rawOutbound, self::MAX_OUTBOUND],
            'acks' => [$input['acks'], self::MAX_ACKS],
        ]);

        if ($overCap !== []) {
            return $this->validationError($overCap);
        }

        $validator = Validator::make($input, [
            'presence' => ['required', 'array'],
            'presence.ready_inboxes' => ['present', 'array', 'max:'.self::MAX_READY_INBOXES],
            'presence.ready_inboxes.*' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && ! Address::isValidLocalPart($value)) {
                        $fail("The $attribute field must be a valid postmaster local part.");
                    }
                },
            ],
            'outbound' => ['array', 'max:'.self::MAX_OUTBOUND],
            'outbound.*' => ['array'],
            'outbound.*.id' => ['required', 'string', 'max:255'],
            'outbound.*.type' => ['required', 'string', Rule::in(MessageType::values())],
            'outbound.*.version' => ['required', 'integer'],
            'outbound.*.from' => ['required', 'string', 'max:255', $this->addressRule()],
            'outbound.*.to' => ['required', 'string', 'max:255', $this->addressRule()],
            'outbound.*.created_at' => ['required', 'string', 'date_format:Y-m-d\\TH:i:s\\Z'],
            'outbound.*.message_id' => ['required', 'string', 'max:255'],
            'outbound.*.body' => [
                'required',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $value instanceof stdClass) {
                        $fail("The $attribute field must be a JSON object.");
                    }
                },
            ],
            'outbound.*.refs' => ['present', 'array', 'size:0'],
            'outbound.*.signature' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'acks' => ['array', 'max:'.self::MAX_ACKS],
            'acks.*' => ['string', 'max:255'],
            'cursor' => ['nullable', 'string', 'max:255'],
            'probe_response' => ['sometimes', 'required', 'array'],
            'probe_response.probe_id' => ['required_with:probe_response', 'string', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/'],
            'probe_response.digest' => ['required_with:probe_response', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ]);

        $validator->after(function ($validator) use ($rawOutbound, $hasProbeResponse, $rawProbeResponse): void {
            // A JSON list decodes to a PHP array and passes the `array` rule; only objects are envelopes.
            if (is_array($rawOutbound)) {
                foreach ($rawOutbound as $index => $item) {
                    if (! $item instanceof stdClass) {
                        $validator->errors()->add("outbound.$index", "The outbound.$index field must be a JSON object.");
                    }
                }
            }

            if ($hasProbeResponse && ! $rawProbeResponse instanceof stdClass) {
                $validator->errors()->add('probe_response', 'The probe_response field must be a JSON object.');
            }
        });

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        /** @var array{presence: array{ready_inboxes: list<string>}, outbound: list<array<string, mixed>>, acks: list<string>, cursor: string|null, probe_response?: array{probe_id: string, digest: string}} $validated */
        $validated = $validator->validated();

        return [
            'ready_inboxes' => $validated['presence']['ready_inboxes'],
            'outbound' => $validated['outbound'],
            'acks' => $validated['acks'],
            'cursor' => $validated['cursor'],
            'probe_response' => $validated['probe_response'] ?? null,
        ];
    }

    /**
     * @param  array<string, array{mixed, int}>  $fields
     * @return array<string, list<string>>
     */
    private function overCapErrors(array $fields): array
    {
        $errors = [];

        foreach ($fields as $field => [$value, $max]) {
            if (is_array($value) && count($value) > $max) {
                $errors[$field] = ["The $field field must not have more than $max items."];
            }
        }

        return $errors;
    }

    /** @return \Closure(string, mixed, \Closure(string): void): void */
    private function addressRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            try {
                Address::parse($value);
            } catch (InvalidArgumentException) {
                $fail("The $attribute field must be a valid postmaster address.");
            }
        };
    }

    /** @param array<string, mixed> $wireEnvelope */
    private function makeEnvelope(array $wireEnvelope): Envelope
    {
        $envelope = new Envelope([
            'id' => $wireEnvelope['id'],
            'type' => $wireEnvelope['type'],
            'version' => $wireEnvelope['version'],
            'from_address' => $wireEnvelope['from'],
            'to_address' => $wireEnvelope['to'],
            'body' => $wireEnvelope['body'],
            'refs' => $wireEnvelope['refs'],
            'message_id' => $wireEnvelope['message_id'],
            'signature' => $wireEnvelope['signature'],
        ]);
        $envelope->created_at = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $wireEnvelope['created_at'], 'UTC');

        return $envelope;
    }

    private function resolveSpoke(int $userId, int $tokenId, CarbonImmutable $now): Spoke
    {
        DB::table('spokes')->insertOrIgnore([
            'user_id' => $userId,
            'token_id' => $tokenId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return Spoke::query()->where('token_id', $tokenId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Claim every advertised inbox for the polling user (first user wins, forever) and
     * replace this spoke's routing set with the claimed rows. The persisted rows — not
     * the request array — are what delivery and acknowledgement later consult.
     *
     * @param  list<string>  $readyInboxes
     */
    private function refreshRouting(Spoke $spoke, int $userId, array $readyInboxes, ?string $cursor, CarbonImmutable $now): void
    {
        $inboxIds = $readyInboxes === [] ? [] : $this->claimInboxes($userId, $readyInboxes, $now);

        /** @var list<int> $current */
        $current = DB::table('spoke_inboxes')->where('spoke_id', $spoke->id)->pluck('inbox_id')->all();

        foreach (array_chunk(array_values(array_diff($current, $inboxIds)), self::QUERY_CHUNK) as $stale) {
            DB::table('spoke_inboxes')->where('spoke_id', $spoke->id)->whereIn('inbox_id', $stale)->delete();
        }

        foreach (array_chunk(array_values(array_diff($inboxIds, $current)), self::QUERY_CHUNK) as $fresh) {
            DB::table('spoke_inboxes')->insertOrIgnore(array_map(fn (int $inboxId): array => [
                'spoke_id' => $spoke->id,
                'inbox_id' => $inboxId,
                'created_at' => $now,
                'updated_at' => $now,
            ], $fresh));
        }

        $spoke->forceFill([
            'last_polled_at' => $now,
            'last_cursor' => $cursor,
        ])->save();
    }

    /**
     * Race-safe first-claim: insertOrIgnore, then read back and let the database decide who
     * owns each local part. A failed statement would abort the whole transaction on
     * PostgreSQL, so a unique violation is never caught here.
     *
     * @param  non-empty-list<string>  $localParts
     * @return list<int>
     */
    private function claimInboxes(int $userId, array $localParts, CarbonImmutable $now): array
    {
        foreach (array_chunk($localParts, self::QUERY_CHUNK) as $chunk) {
            DB::table('inboxes')->insertOrIgnore(array_map(fn (string $localPart): array => [
                'user_id' => $userId,
                'local_part' => $localPart,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        /** @var Collection<int, Inbox> $inboxes */
        $inboxes = new Collection;

        foreach (array_chunk($localParts, self::QUERY_CHUNK) as $chunk) {
            $inboxes = $inboxes->merge(Inbox::query()->whereIn('local_part', $chunk)->get(['id', 'user_id', 'local_part']));
        }

        $foreign = $inboxes->where('user_id', '!=', $userId)->pluck('local_part')->sort()->values()->all();

        if ($foreign !== []) {
            throw new ApiErrorException(409, 'inbox_claimed', 'One or more advertised inboxes are owned by another user.', [
                'inboxes' => $foreign,
            ]);
        }

        if ($inboxes->count() !== count($localParts)) {
            throw new RuntimeException('Postmaster inbox claims could not be resolved.');
        }

        return array_values($inboxes->map(fn (Inbox $inbox): int => $inbox->id)->all());
    }

    /**
     * Every outbound `from` must be a local address whose inbox the sending user owns.
     * This binds a sender to its user, not to a specific spoke — see docs/postmaster.md.
     *
     * @param  list<Envelope>  $envelopes
     */
    private function assertSendersOwned(int $userId, array $envelopes, string $serverId): void
    {
        if ($envelopes === []) {
            return;
        }

        $senders = [];

        foreach ($envelopes as $index => $envelope) {
            $from = Address::parse($envelope->from_address);

            if (! $from->isLocal($serverId)) {
                throw $this->senderNotOwned($index, $envelope->from_address);
            }

            $senders[$index] = $from->localPart;
        }

        $owned = [];

        foreach (array_chunk(array_values(array_unique($senders)), self::QUERY_CHUNK) as $chunk) {
            $owned = [...$owned, ...Inbox::query()->where('user_id', $userId)->whereIn('local_part', $chunk)->pluck('local_part')->all()];
        }

        $owned = array_flip($owned);

        foreach ($senders as $index => $localPart) {
            if (! isset($owned[$localPart])) {
                throw $this->senderNotOwned($index, $envelopes[$index]->from_address);
            }
        }
    }

    private function senderNotOwned(int $index, string $from): ApiErrorException
    {
        return new ApiErrorException(403, 'sender_not_owned', 'The envelope sender must be an inbox owned by the authenticated user on this server.', [
            'index' => $index,
            'from' => $from,
        ]);
    }

    private function storeEnvelope(Envelope $envelope, string $serverId, CarbonImmutable $now): void
    {
        $to = Address::parse($envelope->to_address);

        DB::table('messages')->insertOrIgnore([
            'id' => $envelope->id,
            'type' => $envelope->type->value,
            'version' => $envelope->version,
            'from_address' => $envelope->from_address,
            'to_address' => $envelope->to_address,
            'to_local_part' => $to->localPart,
            'to_server_id' => $to->serverId,
            'body' => json_encode($envelope->body, JSON_THROW_ON_ERROR),
            'refs' => json_encode($envelope->refs, JSON_THROW_ON_ERROR),
            'message_id' => $envelope->message_id,
            'signature' => $envelope->signature,
            'status' => $to->isLocal($serverId) ? MessageStatus::Pending->value : MessageStatus::PendingRelay->value,
            'received_at' => $now,
            'created_at' => $envelope->created_at,
            'updated_at' => $now,
        ]);
    }

    /**
     * Acks are scoped by inbox ownership, so a spoke that has stopped advertising an inbox
     * can still acknowledge the batch it already received.
     *
     * @param  list<string>  $acks
     */
    private function processAcks(int $userId, array $acks, string $serverId, CarbonImmutable $now): void
    {
        foreach (array_chunk(array_values(array_unique($acks)), self::QUERY_CHUNK) as $chunk) {
            Envelope::query()
                ->whereIn('message_id', $chunk)
                ->where('to_server_id', $serverId)
                ->whereIn('to_local_part', $this->ownedLocalParts($userId))
                ->where('status', '!=', MessageStatus::Acked->value)
                ->update([
                    'status' => MessageStatus::Acked->value,
                    'acked_at' => $now,
                ]);
        }
    }

    /**
     * Delivery is scoped by this spoke's persisted routing rows, restricted to inboxes
     * its user owns. Server-assigned `received_at` is the priority key on every driver.
     *
     * @return Collection<int, Envelope>
     */
    private function inbound(Spoke $spoke, int $userId, string $serverId): Collection
    {
        $limit = max(0, (int) config('capstan.postmaster.poll.max_inbound', 50));

        if ($limit === 0) {
            return new Collection;
        }

        return Envelope::query()
            ->where('to_server_id', $serverId)
            ->whereIn('to_local_part', $this->routedLocalParts($spoke, $userId))
            ->whereIn('status', [MessageStatus::Pending->value, MessageStatus::Delivered->value])
            ->orderBy('received_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /** @param Collection<int, Envelope> $inbound */
    private function markDelivered(Collection $inbound, CarbonImmutable $now): void
    {
        foreach ($inbound->pluck('id')->chunk(self::QUERY_CHUNK) as $ids) {
            // First delivery time survives redelivery; status flips once, in bulk.
            Envelope::query()->whereIn('id', $ids)->whereNull('delivered_at')->update(['delivered_at' => $now]);
            Envelope::query()->whereIn('id', $ids)->where('status', MessageStatus::Pending->value)->update(['status' => MessageStatus::Delivered->value]);
        }
    }

    /** @return \Closure(QueryBuilder): void */
    private function ownedLocalParts(int $userId): \Closure
    {
        return function (QueryBuilder $query) use ($userId): void {
            $query->select('local_part')->from('inboxes')->where('user_id', $userId);
        };
    }

    /** @return \Closure(QueryBuilder): void */
    private function routedLocalParts(Spoke $spoke, int $userId): \Closure
    {
        return function (QueryBuilder $query) use ($spoke, $userId): void {
            $query
                ->select('inboxes.local_part')
                ->from('inboxes')
                ->join('spoke_inboxes', 'spoke_inboxes.inbox_id', '=', 'inboxes.id')
                ->where('spoke_inboxes.spoke_id', $spoke->id)
                ->where('inboxes.user_id', $userId);
        };
    }

    /** @return array<string, mixed> */
    private function wireEnvelope(Envelope $envelope): array
    {
        return [...$envelope->signablePayload(), 'signature' => $envelope->signature];
    }

    /** @param array<string, mixed> $errors */
    private function validationError(array $errors): JsonResponse
    {
        return ApiError::response(422, 'validation_failed', 'The given data was invalid.', [
            'errors' => $errors,
        ]);
    }
}
