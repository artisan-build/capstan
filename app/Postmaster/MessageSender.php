<?php

namespace App\Postmaster;

use App\Enums\MessageStatus;
use App\Http\ApiErrorException;
use App\Models\Envelope;
use App\Models\Inbox;
use App\Support\Address;
use App\Support\JsonCanonicalizer;
use App\Support\ServerIdentity;
use ArtisanBuild\BuiltForCloud\Hmac\SigningRootMac;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class MessageSender
{
    public function __construct(
        private SigningRootMac $signer,
        private ServerIdentity $identity,
    ) {}

    public function send(string $actorId, Envelope $envelope, CarbonImmutable $receivedAt, ?int $batchIndex = null): bool
    {
        $serverId = $this->identity->id();
        $from = Address::parse($envelope->from_address);

        if (! $from->isLocal($serverId)
            || ! Inbox::query()->where('actor_id', $actorId)->where('local_part', $from->localPart)->exists()) {
            throw new ApiErrorException(
                403,
                'sender_not_owned',
                'The envelope sender must be an inbox owned by the authenticated user on this server.',
                $batchIndex === null ? [] : ['index' => $batchIndex, 'from' => $envelope->from_address],
            );
        }

        $to = Address::parse($envelope->to_address);
        $envelope->received_at = $receivedAt;
        $mac = $this->signer->mac(JsonCanonicalizer::encode($envelope->signablePayload()));
        $envelope->signature = $mac->lowercaseHexMac;
        $envelope->signing_key_id = $mac->keyId;
        $envelope->status = $to->isLocal($serverId) ? MessageStatus::Pending : MessageStatus::PendingRelay;

        return DB::table('messages')->insertOrIgnore([
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
            'signing_key_id' => $envelope->signing_key_id,
            'status' => $envelope->status->value,
            'received_at' => $receivedAt,
            'created_at' => $envelope->created_at,
            'updated_at' => $receivedAt,
        ]) === 1;
    }
}
