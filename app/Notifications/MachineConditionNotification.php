<?php

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

final class MachineConditionNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly int $machineId,
        private readonly string $displayName,
        private readonly string $findingCode,
        private readonly string $transition,
        private readonly CarbonImmutable $occurredAt,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, int|string> */
    public function toArray(object $notifiable): array
    {
        return [
            'machine_id' => $this->machineId,
            'display_name' => $this->displayName,
            'finding_code' => $this->findingCode,
            'state_transition' => $this->transition,
            'occurred_at' => $this->occurredAt->toJSON(),
        ];
    }
}
