<?php

namespace App\Support;

use App\Models\Spoke;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class BoundCredentialSpokeResolver
{
    public function resolve(string $actorId, string $credentialId, CarbonImmutable $now): Spoke
    {
        DB::table('spokes')->insertOrIgnore([
            'actor_id' => $actorId,
            'credential_id' => $credentialId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return Spoke::query()
            ->where('credential_id', $credentialId)
            ->where('actor_id', $actorId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function heartbeat(Spoke $spoke, CarbonImmutable $now): void
    {
        $spoke->forceFill(['last_polled_at' => $now])->save();
    }
}
