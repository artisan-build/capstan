<?php

namespace App\Postmaster;

use App\Models\Spoke;
use App\Models\WorkItem;
use App\Models\WorkOrchestratorSeat;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\RolePolicy;
use ArtisanBuild\BuiltForCloud\SystemAuthorityContext;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class RetireDeadSpokes
{
    public function __construct(private SystemAuthorityContext $authority) {}

    public function __invoke(): int
    {
        if (! $this->authority->active()) {
            throw new LogicException('Postmaster spoke retirement requires system authority.');
        }

        return DB::transaction(function (): int {
            $ids = Spoke::query()
                ->whereNull('retired_at')
                ->where('credential_backed', true)
                ->lockForUpdate()
                ->pluck('id');

            if ($ids->isEmpty()) {
                return 0;
            }

            $retired = 0;

            foreach ($ids as $id) {
                $spoke = Spoke::query()->whereKey($id)->whereNull('retired_at')->lockForUpdate()->first();

                if ($spoke === null) {
                    continue;
                }

                if ($spoke->orphaned_at !== null || $this->ownerIsUnavailable($spoke)) {
                    if ($spoke->orphaned_at === null) {
                        $spoke->inboxes()->detach();
                        $spoke->forceFill(['orphaned_at' => now()])->save();
                    }

                    continue;
                }

                $credential = Credential::query()->whereKey($spoke->credential_id)->first();
                $dead = $credential === null
                    || $credential->revoked_at !== null
                    || ($credential->expires_at !== null && ! $credential->expires_at->isAfter(now()));

                if (! $dead || $this->hasLiveAuthority($spoke, now())) {
                    continue;
                }

                $spoke->inboxes()->detach();
                $spoke->forceFill(['retired_at' => now(), 'retired_by' => null])->save();
                $retired++;
            }

            return $retired;
        });
    }

    private function hasLiveAuthority(Spoke $spoke, CarbonInterface $now): bool
    {
        return WorkItem::query()
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
    }

    private function ownerIsUnavailable(Spoke $spoke): bool
    {
        $owner = User::query()->find($spoke->actor_id);

        if (! $owner instanceof User
            || $owner->status !== 'active'
            || ! RolePolicy::canUseProduct($owner->role)) {
            return true;
        }

        return OffboardedSubject::query()
            ->where('user_id', $spoke->actor_id)
            ->orWhere(function (Builder $query) use ($spoke): void {
                $query->where('subject_ref', 'capstan-user:'.$spoke->actor_id)
                    ->where('user_id', OffboardedSubject::SUBJECT_ROW);
            })
            ->exists();
    }
}
