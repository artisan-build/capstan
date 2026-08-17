<?php

namespace App\Enums;

enum ProbeStatus: string
{
    case Issued = 'issued';
    case Awaiting = 'awaiting';
    case Passed = 'passed';
    case Failed = 'failed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
