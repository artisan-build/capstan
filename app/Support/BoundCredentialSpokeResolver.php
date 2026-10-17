<?php

namespace App\Support;

use App\Models\Spoke;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class BoundCredentialSpokeResolver
{
    public function resolve(
        string $actorId,
        string $credentialId,
        CarbonImmutable $now,
        bool $credentialBacked = true,
    ): Spoke {
        $existing = Spoke::query()
            ->where('credential_id', $credentialId)
            ->where('actor_id', $actorId)
            ->where('credential_backed', $credentialBacked)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        DB::table('spokes')->insertOrIgnore([
            'actor_id' => $actorId,
            'credential_id' => $credentialId,
            'credential_backed' => $credentialBacked,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return Spoke::query()
            ->where('credential_id', $credentialId)
            ->where('actor_id', $actorId)
            ->where('credential_backed', $credentialBacked)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function heartbeat(Spoke $spoke, CarbonImmutable $now): void
    {
        Spoke::query()->whereKey($spoke->id)->update([
            'last_polled_at' => $now,
            'work_heartbeat_notified_at' => null,
        ]);
        $spoke->refresh();
    }
}
