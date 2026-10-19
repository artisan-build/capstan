<?php

namespace App\Enums;

enum MachineState: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Inactive = 'inactive';
    case Unhealthy = 'unhealthy';
    case Orphaned = 'orphaned';
    case Retired = 'retired';
}
