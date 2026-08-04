<?php

namespace App\Enums;

enum DeviceCodeStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Denied = 'denied';
}
