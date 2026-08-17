<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Acked = 'acked';
    case PendingRelay = 'pending_relay';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
