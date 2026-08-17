<?php

namespace App\Enums;

enum SpokeLiveness: string
{
    case Unknown = 'unknown';
    case Green = 'green';
    case Red = 'red';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => __('Pending'),
            self::Green => __('Passing'),
            self::Red => __('Failing'),
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
