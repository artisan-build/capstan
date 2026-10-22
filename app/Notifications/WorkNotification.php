<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

final class WorkNotification extends Notification
{
    use Queueable;

    /** @param array<string, int|string> $data */
    public function __construct(private readonly array $data) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, int|string> */
    public function toArray(object $notifiable): array
    {
        return $this->data;
    }
}
