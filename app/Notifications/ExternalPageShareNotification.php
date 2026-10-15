<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ExternalPageShareNotification extends Notification
{
    public function __construct(
        public readonly string $shareId,
        public readonly string $pageTitle,
        public readonly string $viewerUrl,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A page was shared with you')
            ->line($this->pageTitle)
            ->action('View page', $this->viewerUrl);
    }
}
