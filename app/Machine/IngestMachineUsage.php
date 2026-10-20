<?php

namespace App\Machine;

use App\Models\MachineUsageProvider;
use App\Models\MachineUsageReport;
use App\Models\Spoke;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class IngestMachineUsage
{
    public function __construct(private MachineConditionNotifier $conditions) {}

    /**
     * @param  array<string, mixed>  $usage
     * @return 'accepted'|'not_due'
     */
    public function ingest(Spoke $spoke, array $usage, CarbonImmutable $receivedAt): string
    {
        return DB::transaction(function () use ($spoke, $usage, $receivedAt): string {
            Spoke::query()->whereKey($spoke->id)->lockForUpdate()->firstOrFail();
            $report = MachineUsageReport::query()->where('spoke_id', $spoke->id)->lockForUpdate()->first();

            if ($report !== null && $report->last_attempt_at->greaterThan($receivedAt->subSeconds(300))) {
                return 'not_due';
            }

            $unavailableSince = null;
            if ($usage['availability'] === 'unavailable') {
                $unavailableSince = $report instanceof MachineUsageReport && $report->unavailable_since !== null
                    ? $report->unavailable_since
                    : $receivedAt;
            }
            $values = [
                'source' => $usage['source'],
                'source_version' => $usage['source_version'],
                'availability' => $usage['availability'],
                'collected_at' => $usage['collected_at'],
                'last_attempt_at' => $receivedAt,
                'failure_code' => $usage['failure_code'],
                'unavailable_since' => $unavailableSince,
            ];
            if ($report === null) {
                $report = new MachineUsageReport(['spoke_id' => $spoke->id, ...$values]);
            } else {
                $report->fill($values);
            }

            if ($usage['availability'] === 'available') {
                $report->last_success_received_at = $receivedAt;
            }
            $report->save();

            if ($usage['availability'] === 'unavailable') {
                if ($unavailableSince->lessThanOrEqualTo($receivedAt->subMinutes(15))) {
                    $this->conditions->open($spoke, MachineConditionNotifier::USAGE_UNAVAILABLE, $receivedAt);
                }

                return 'accepted';
            }

            $this->conditions->recover($spoke, MachineConditionNotifier::USAGE_UNAVAILABLE, $receivedAt);

            $report->providers()->delete();
            foreach ($usage['providers'] as $providerValues) {
                $windows = $providerValues['windows'];
                unset($providerValues['windows']);
                /** @var MachineUsageProvider $provider */
                $provider = $report->providers()->create($providerValues);
                foreach ($windows as $window) {
                    $provider->windows()->create($window);
                }
            }

            return 'accepted';
        });
    }
}
