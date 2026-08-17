<?php

namespace App\Http\Controllers\Api;

use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Features\Postmaster;
use App\Http\ApiError;
use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Models\Spoke;
use App\Models\User;
use App\Support\Address;
use App\Support\EnvelopeSigner;
use App\Support\ServerIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use JsonException;
use Laravel\Pennant\Feature;
use stdClass;

class PollController extends Controller
{
    public function __invoke(Request $request, EnvelopeSigner $signer, ServerIdentity $identity): JsonResponse
    {
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

        /** @var array{inbound: list<array<string, mixed>>, cursor: string|null} $response */
        $response = DB::transaction(function () use ($user, $token, $validation, $envelopes, $serverId): array {
            $spoke = $this->resolveSpoke($user->id, (int) $token->getKey());
            $readyInboxes = array_values(array_unique($validation['ready_inboxes']));

            $this->refreshRouting($spoke, $readyInboxes, $validation['cursor']);

            foreach ($envelopes as $envelope) {
                $this->storeEnvelope($envelope, $serverId);
            }

            $this->processAcks($validation['acks'], $readyInboxes, $serverId);
            $inbound = $this->inbound($readyInboxes, $serverId);

            foreach ($inbound as $envelope) {
                $envelope->forceFill([
                    'status' => MessageStatus::Delivered,
                    'delivered_at' => now(),
                ])->save();
            }

            return [
                'inbound' => $inbound->map(fn (Envelope $envelope): array => $this->wireEnvelope($envelope))->values()->all(),
                'cursor' => $inbound->last()?->id,
            ];
        });

        return new JsonResponse($response);
    }

    /**
     * @return array{ready_inboxes: list<string>, outbound: list<array<string, mixed>>, acks: list<string>, cursor: string|null}|JsonResponse
     */
    private function validatePayload(stdClass $payload): array|JsonResponse
    {
        $presence = $payload->presence ?? null;
        $rawOutbound = $payload->outbound ?? [];
        $input = [
            'presence' => $presence instanceof stdClass ? get_object_vars($presence) : $presence,
            'outbound' => is_array($rawOutbound)
                ? array_map(static fn (mixed $item): mixed => $item instanceof stdClass ? get_object_vars($item) : $item, $rawOutbound)
                : $rawOutbound,
            'acks' => $payload->acks ?? [],
            'cursor' => $payload->cursor ?? null,
        ];

        $validator = Validator::make($input, [
            'presence' => ['required', 'array'],
            'presence.ready_inboxes' => ['present', 'array'],
            'presence.ready_inboxes.*' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && ! Address::isValidLocalPart($value)) {
                        $fail("The $attribute field must be a valid postmaster local part.");
                    }
                },
            ],
            'outbound' => ['array'],
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
            'acks' => ['array'],
            'acks.*' => ['string', 'max:255'],
            'cursor' => ['nullable', 'string', 'max:255'],
        ]);

        $validator->after(function ($validator) use ($presence, $rawOutbound, $input): void {
            if ($presence instanceof stdClass) {
                $readyInboxes = $presence->ready_inboxes ?? null;

                if (is_array($readyInboxes) && ! array_is_list($readyInboxes)) {
                    $validator->errors()->add('presence.ready_inboxes', 'The presence.ready_inboxes field must be a JSON array.');
                }
            }

            if (is_array($rawOutbound)) {
                foreach ($rawOutbound as $index => $item) {
                    if (! $item instanceof stdClass) {
                        $validator->errors()->add("outbound.$index", "The outbound.$index field must be a JSON object.");
                    }
                }
            }

            if (is_array($input['acks']) && ! array_is_list($input['acks'])) {
                $validator->errors()->add('acks', 'The acks field must be a JSON array.');
            }
        });

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        /** @var array{presence: array{ready_inboxes: list<string>}, outbound: list<array<string, mixed>>, acks: list<string>, cursor: string|null} $validated */
        $validated = $validator->validated();

        return [
            'ready_inboxes' => $validated['presence']['ready_inboxes'],
            'outbound' => $validated['outbound'],
            'acks' => $validated['acks'],
            'cursor' => $validated['cursor'],
        ];
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

    private function resolveSpoke(int $userId, int $tokenId): Spoke
    {
        $now = now();

        DB::table('spokes')->insertOrIgnore([
            'user_id' => $userId,
            'token_id' => $tokenId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return Spoke::query()->where('token_id', $tokenId)->lockForUpdate()->firstOrFail();
    }

    /** @param list<string> $readyInboxes */
    private function refreshRouting(Spoke $spoke, array $readyInboxes, ?string $cursor): void
    {
        $inboxes = $spoke->inboxes();

        if ($readyInboxes === []) {
            $inboxes->delete();
        } else {
            $inboxes->whereNotIn('local_part', $readyInboxes)->delete();
        }

        $now = now();
        $rows = array_map(fn (string $localPart): array => [
            'spoke_id' => $spoke->id,
            'local_part' => $localPart,
            'created_at' => $now,
            'updated_at' => $now,
        ], $readyInboxes);

        if ($rows !== []) {
            DB::table('spoke_inboxes')->insertOrIgnore($rows);
        }

        $spoke->forceFill([
            'last_polled_at' => $now,
            'last_cursor' => $cursor,
        ])->save();
    }

    private function storeEnvelope(Envelope $envelope, string $serverId): void
    {
        $to = Address::parse($envelope->to_address);
        $now = now();

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
            'created_at' => $envelope->created_at,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  list<string>  $acks
     * @param  list<string>  $readyInboxes
     */
    private function processAcks(array $acks, array $readyInboxes, string $serverId): void
    {
        if ($acks === [] || $readyInboxes === []) {
            return;
        }

        $messages = Envelope::query()
            ->whereIn('message_id', array_unique($acks))
            ->where('to_server_id', $serverId)
            ->whereIn('to_local_part', $readyInboxes)
            ->where('status', '!=', MessageStatus::Acked->value)
            ->get();

        foreach ($messages as $message) {
            $message->forceFill([
                'status' => MessageStatus::Acked,
                'acked_at' => now(),
            ])->save();
        }
    }

    /**
     * @param  list<string>  $readyInboxes
     * @return Collection<int, Envelope>
     */
    private function inbound(array $readyInboxes, string $serverId): Collection
    {
        $limit = max(0, (int) config('capstan.postmaster.poll.max_inbound', 50));

        if ($readyInboxes === [] || $limit === 0) {
            return new Collection;
        }

        return Envelope::query()
            ->where('to_server_id', $serverId)
            ->whereIn('to_local_part', $readyInboxes)
            ->whereIn('status', [MessageStatus::Pending->value, MessageStatus::Delivered->value])
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
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
