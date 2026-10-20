<?php

namespace App\Machine;

use App\Models\MachineConditionState;
use App\Models\Spoke;
use App\Notifications\MachineConditionNotification;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class MachineConditionNotifier
{
    public const string PROBE_FAILURE = 'probe_failure';

    public const string INACTIVE = 'inactive';

    public const string USAGE_UNAVAILABLE = 'usage_source_unavailable';

    public const string RUNNER_CHECK = 'runner_check';

    public function open(Spoke $machine, string $code, CarbonImmutable $occurredAt): bool
    {
        return DB::transaction(function () use ($machine, $code, $occurredAt): bool {
            Spoke::query()->whereKey($machine->id)->lockForUpdate()->firstOrFail();
            $state = MachineConditionState::query()
                ->where('spoke_id', $machine->id)
                ->where('finding_code', $code)
                ->lockForUpdate()
                ->first();
            if ($state?->active === true) {
                return false;
            }

            $state ??= new MachineConditionState(['spoke_id' => $machine->id, 'finding_code' => $code]);
            $state->forceFill([
                'active' => true,
                'transition_key' => hash('sha256', $machine->id."\0".$code."\0".$occurredAt->toJSON()),
                'opened_at' => $occurredAt,
                'closed_at' => null,
            ])->save();

            return $this->send($machine, $this->notification($machine, $code, 'started', $occurredAt));
        });
    }

    public function recover(Spoke $machine, string $code, CarbonImmutable $occurredAt): bool
    {
        return DB::transaction(function () use ($machine, $code, $occurredAt): bool {
            Spoke::query()->whereKey($machine->id)->lockForUpdate()->firstOrFail();
            $state = MachineConditionState::query()
                ->where('spoke_id', $machine->id)
                ->where('finding_code', $code)
                ->lockForUpdate()
                ->first();
            if (! $state instanceof MachineConditionState || ! $state->active) {
                return false;
            }

            $state->forceFill(['active' => false, 'closed_at' => $occurredAt])->save();

            return $this->send($machine, $this->notification($machine, $code, 'recovered', $occurredAt));
        });
    }

    private function notification(Spoke $machine, string $code, string $transition, CarbonImmutable $occurredAt): MachineConditionNotification
    {
        $name = trim((string) ($machine->name ?? $machine->getAttribute('device_name') ?? ''));

        return new MachineConditionNotification(
            $machine->id,
            $name !== '' ? $name : 'Machine #'.$machine->id,
            $code,
            $transition,
            $occurredAt,
        );
    }

    private function send(Spoke $machine, MachineConditionNotification $notification): bool
    {
        $owner = User::query()->find($machine->actor_id);
        if ($owner instanceof User) {
            $owner->notify($notification);
        }

        return true;
    }
}
