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
        mixed $deviceName = null,
    ): Spoke {
        $deviceName = $this->sanitizeDeviceName($deviceName);
        $existing = Spoke::query()
            ->where('credential_id', $credentialId)
            ->where('actor_id', $actorId)
            ->where('credential_backed', $credentialBacked)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            $this->refreshDeviceName($existing, $deviceName, $now);

            return $existing;
        }

        $attributes = [
            'actor_id' => $actorId,
            'credential_id' => $credentialId,
            'credential_backed' => $credentialBacked,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($deviceName !== null) {
            $attributes['device_name'] = $deviceName;
        }

        DB::table('spokes')->insertOrIgnore($attributes);

        $spoke = Spoke::query()
            ->where('credential_id', $credentialId)
            ->where('actor_id', $actorId)
            ->where('credential_backed', $credentialBacked)
            ->lockForUpdate()
            ->firstOrFail();

        $this->refreshDeviceName($spoke, $deviceName, $now);

        return $spoke;
    }

    public function heartbeat(Spoke $spoke, CarbonImmutable $now): void
    {
        Spoke::query()->whereKey($spoke->id)->update([
            'last_polled_at' => $now,
            'work_heartbeat_notified_at' => null,
        ]);
        $spoke->refresh();
    }

    private function sanitizeDeviceName(mixed $deviceName): ?string
    {
        if (! is_string($deviceName)) {
            return null;
        }

        $deviceName = preg_replace('/\p{Cc}+/u', '', $deviceName);

        if (! is_string($deviceName)) {
            return null;
        }

        $deviceName = trim($deviceName);

        return $deviceName === '' ? null : mb_substr($deviceName, 0, 64);
    }

    private function refreshDeviceName(Spoke $spoke, ?string $deviceName, CarbonImmutable $now): void
    {
        if ($deviceName === null || $spoke->getAttribute('device_name') === $deviceName) {
            return;
        }

        DB::table('spokes')->where('id', $spoke->id)->update([
            'device_name' => $deviceName,
            'updated_at' => $now,
        ]);
        $spoke->setAttribute('device_name', $deviceName);
    }
}
