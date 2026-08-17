<?php

namespace App\Postmaster;

use App\Models\Spoke;
use App\Models\SpokeProbe;
use Illuminate\Support\Facades\Log;

class LogProbeFailureNotifier implements ProbeFailureNotifier
{
    public function notify(Spoke $spoke, SpokeProbe $probe): void
    {
        Log::warning('Postmaster spoke probe failed.', [
            'spoke_id' => $spoke->id,
            'probe_id' => $probe->probe_id,
            'probe_failed_at' => $spoke->probe_failed_at?->toISOString(),
        ]);
    }
}
