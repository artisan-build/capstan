<?php

namespace App\Postmaster;

use App\Models\Spoke;
use App\Models\WorkItem;
use App\Models\WorkOrchestratorSeat;
use ArtisanBuild\BuiltForCloud\SystemAuthorityContext;
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
                ->where(function (Builder $query): void {
                    $query->whereNotExists(function ($credentials): void {
                        $credentials->selectRaw('1')
                            ->from('credentials')
                            ->whereColumn('credentials.id', 'spokes.credential_id');
                    })->orWhereExists(function ($credentials): void {
                        $credentials->selectRaw('1')
                            ->from('credentials')
                            ->whereColumn('credentials.id', 'spokes.credential_id')
                            ->where(function ($dead): void {
                                $dead->whereNotNull('credentials.revoked_at')
                                    ->orWhere('credentials.expires_at', '<=', now());
                            });
                    });
                })
                ->lockForUpdate()
                ->pluck('id');

            if ($ids->isEmpty()) {
                return 0;
            }

            $retired = 0;

            foreach ($ids as $id) {
                $spoke = Spoke::query()->whereKey($id)->whereNull('retired_at')->lockForUpdate()->first();

                if ($spoke === null || $this->hasLiveAuthority($spoke, now())) {
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
}
