<?php

namespace App\Postmaster;

use App\Enums\ProbeStatus;
use App\Enums\SpokeLiveness;
use App\Http\ApiErrorException;
use App\Models\Spoke;
use App\Models\SpokeProbe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class ProbeManager
{
    /**
     * @param  array{probe_id: string, digest: string}|null  $response
     */
    public function respond(Spoke $spoke, ?array $response, CarbonImmutable $now): ?SpokeProbe
    {
        if ($response === null) {
            return null;
        }

        $probe = SpokeProbe::query()
            ->where('probe_id', $response['probe_id'])
            ->lockForUpdate()
            ->first();

        if (! $probe instanceof SpokeProbe
            || $probe->spoke_id !== $spoke->id
            || $probe->status !== ProbeStatus::Awaiting
            || $probe->expires_at->lessThanOrEqualTo($now)) {
            throw new ApiErrorException(
                422,
                'invalid_probe_response',
                'The probe response does not match an active challenge.',
            );
        }

        if (hash_equals(hash('sha256', $probe->nonce), $response['digest'])) {
            $probe->forceFill([
                'status' => ProbeStatus::Passed,
                'responded_at' => $now,
            ])->save();
            $spoke->forceFill([
                'probe_status' => SpokeLiveness::Green,
                'probe_failed_at' => null,
            ])->save();

            return null;
        }

        $this->fail($spoke, $probe, $now, responded: true);

        return $probe;
    }

    /**
     * @return array{probe_id: string, nonce: string, algorithm: string}|null
     */
    public function issue(Spoke $spoke, CarbonImmutable $now): ?array
    {
        $outstanding = SpokeProbe::query()
            ->where('spoke_id', $spoke->id)
            ->whereIn('status', [ProbeStatus::Issued->value, ProbeStatus::Awaiting->value])
            ->where('expires_at', '>', $now)
            ->exists();

        if ($outstanding) {
            return null;
        }

        $lastIssuedAt = SpokeProbe::query()
            ->where('spoke_id', $spoke->id)
            ->latest('issued_at')
            ->value('issued_at');
        $interval = max(0, (int) config('capstan.postmaster.probe.interval_seconds', 300));
        $backoff = max(0, (int) config('capstan.postmaster.probe.backoff_seconds', 1800));

        if ($spoke->probe_status === SpokeLiveness::Red
            && $spoke->probe_failed_at !== null) {
            if ($spoke->probe_failed_at->addSeconds($backoff)->isAfter($now)) {
                return null;
            }
        } elseif ($lastIssuedAt !== null && CarbonImmutable::parse($lastIssuedAt)->addSeconds($interval)->isAfter($now)) {
            return null;
        }

        $nonce = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $probe = SpokeProbe::query()->create([
            'spoke_id' => $spoke->id,
            'probe_id' => (string) Str::ulid(),
            'nonce' => $nonce,
            'status' => ProbeStatus::Issued,
            'issued_at' => $now,
            'expires_at' => $now->addSeconds(max(0, (int) config('capstan.postmaster.probe.timeout_seconds', 900))),
        ]);

        // This transport creates and hands off the challenge in one transaction.
        $probe->forceFill(['status' => ProbeStatus::Awaiting])->save();

        return [
            'probe_id' => $probe->probe_id,
            'nonce' => $probe->nonce,
            'algorithm' => 'sha256',
        ];
    }

    public function fail(Spoke $spoke, SpokeProbe $probe, CarbonImmutable $now, bool $responded): void
    {
        $probe->forceFill([
            'status' => ProbeStatus::Failed,
            'responded_at' => $responded ? $now : null,
        ])->save();
        $spoke->forceFill([
            'probe_status' => SpokeLiveness::Red,
            'probe_failed_at' => $now,
        ])->save();
    }
}
