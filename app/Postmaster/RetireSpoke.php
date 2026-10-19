<?php

namespace App\Postmaster;

use App\Models\Spoke;
use App\Models\WorkItem;
use App\Models\WorkOrchestratorSeat;
use ArtisanBuild\BuiltForCloud\Actions\RevokeCredential;
use ArtisanBuild\BuiltForCloud\AuditActor;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class RetireSpoke
{
    public function __construct(private RevokeCredential $revokeCredential) {}

    public function handle(int $spokeId, string $actorId, CarbonImmutable $now): Spoke
    {
        return DB::transaction(function () use ($spokeId, $actorId, $now): Spoke {
            $spoke = Spoke::query()->whereKey($spokeId)->lockForUpdate()->firstOrFail();

            if (! hash_equals($actorId, $spoke->actor_id)) {
                throw new AuthorizationException('You do not own this Postmaster spoke.');
            }

            if ($spoke->retired_at !== null) {
                return $spoke;
            }

            $hasLiveAuthority = WorkItem::query()
                ->where('claimant_spoke_id', $spoke->id)
                ->whereIn('state', ['claimed', 'delivered', 'routed'])
                ->where('lease_until', '>', $now)
                ->whereHas('workTask', fn ($query) => $query->whereNull('cancelled_at'))
                ->exists()
                || WorkOrchestratorSeat::query()
                    ->where('holder_spoke_id', $spoke->id)
                    ->whereNotNull('holder_actor')
                    ->whereNull('stopped_at')
                    ->where('lease_until', '>', $now)
                    ->exists();

            if ($hasLiveAuthority) {
                throw new DomainException('Release or wait for this spoke’s live Work lease before retiring it.');
            }

            if ($spoke->credential_backed) {
                ($this->revokeCredential)($spoke->credential_id, AuditActor::boundUser($actorId));
            } else {
                $spoke->credential_id = (string) Str::uuid7();
            }

            $spoke->inboxes()->detach();
            $spoke->forceFill([
                'retired_at' => $now,
                'retired_by' => $actorId,
            ])->save();

            return $spoke->refresh();
        });
    }
}
