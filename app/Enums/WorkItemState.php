<?php

namespace App\Enums;

enum WorkItemState: string
{
    case Queued = 'queued';
    case Claimed = 'claimed';
    case Delivered = 'delivered';
    case Routed = 'routed';
    case Acted = 'acted';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Acted, self::Failed, self::Cancelled, self::Expired], true);
    }
}
