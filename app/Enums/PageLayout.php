<?php

namespace App\Enums;

enum PageLayout: string
{
    case Plain = 'plain';
    case Report = 'report';
    case Dashboard = 'dashboard';
    case Brief = 'brief';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
