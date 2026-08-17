<?php

namespace App\Enums;

enum SpokeMapStatus: string
{
    case Green = 'green';
    case Red = 'red';
    case Pending = 'pending';
}
