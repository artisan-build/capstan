<?php

namespace App\Enums;

enum SpokeLiveness: string
{
    case Unknown = 'unknown';
    case Green = 'green';
    case Red = 'red';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
