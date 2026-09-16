<?php

namespace App\Postmaster;

use App\Models\Spoke;
use ArtisanBuild\BuiltForCloud\SystemAuthorityContext;
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

            return Spoke::query()->whereKey($ids)->delete();
        });
    }
}
