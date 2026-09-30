<?php

namespace App\Postmaster;

use App\Enums\MessageStatus;
use App\Models\Envelope;
use App\Support\ServerIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;

final readonly class MessageAcknowledger
{
    public function __construct(private ServerIdentity $identity) {}

    /**
     * @param  list<string>  $messageIds
     * @return array{requested_count: int, acknowledged_count: int}
     */
    public function acknowledge(string $actorId, array $messageIds, ?CarbonImmutable $now = null): array
    {
        $unique = array_values(array_unique($messageIds));
        $acknowledged = 0;
        $now ??= CarbonImmutable::now();

        foreach (array_chunk($unique, 500) as $chunk) {
            $acknowledged += Envelope::query()
                ->whereIn('message_id', $chunk)
                ->where('to_server_id', $this->identity->id())
                ->whereIn('to_local_part', function (QueryBuilder $query) use ($actorId): void {
                    $query->select('local_part')->from('inboxes')->where('actor_id', $actorId);
                })
                ->where('status', '!=', MessageStatus::Acked->value)
                ->update([
                    'status' => MessageStatus::Acked->value,
                    'acked_at' => $now,
                ]);
        }

        return ['requested_count' => count($unique), 'acknowledged_count' => $acknowledged];
    }
}
