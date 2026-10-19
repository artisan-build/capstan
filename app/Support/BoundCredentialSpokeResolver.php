<?php

namespace App\Support;

use App\Http\ApiErrorException;
use App\Models\Spoke;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            ->active()
            ->where('credential_id', $credentialId)
            ->where('actor_id', $actorId)
            ->where('credential_backed', $credentialBacked)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            if ($existing->orphaned_at !== null) {
                throw new ApiErrorException(403, 'spoke_orphaned', 'This machine must be reactivated before it can connect.');
            }

            if ($existing->inactive_at !== null) {
                $existing->forceFill([
                    'inactive_at' => null,
                    'updated_at' => $now,
                ])->save();
            }

            $this->refreshDeviceName($existing, $deviceName, $now);

            return $existing;
        }

        $retired = Spoke::query()
            ->where('credential_id', $credentialId)
            ->where('actor_id', $actorId)
            ->where('credential_backed', $credentialBacked)
            ->whereNotNull('retired_at')
            ->lockForUpdate()
            ->first();

        if ($retired !== null) {
            if ($credentialBacked) {
                throw new ApiErrorException(403, 'spoke_retired', 'This Postmaster spoke has been retired.');
            }

            // Synthetic delegated identities are reusable. Tombstone the retired
            // generation's synthetic key so the same principal can mint a fresh spoke.
            $retired->forceFill(['credential_id' => (string) Str::uuid7(), 'updated_at' => $now])->save();
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
            ->active()
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
            'inactive_at' => null,
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
