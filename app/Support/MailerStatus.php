<?php

namespace App\Support;

class MailerStatus
{
    public static function emailDeliveryConfigured(): bool
    {
        $mailer = config('mail.default');

        if ($mailer === null) {
            return false;
        }

        return ! in_array(strtolower((string) $mailer), ['log', 'array', 'null'], true);
    }
}
