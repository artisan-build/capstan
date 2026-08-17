<?php

namespace App\Enums;

enum SpokeMapStatus: string
{
    case Green = 'green';
    case Red = 'red';
    case Pending = 'pending';

    public function label(): string
    {
        return match ($this) {
            self::Green => __('Online'),
            self::Red => __('Offline'),
            self::Pending => __('Pending first probe'),
        };
    }

    public function dotClasses(): string
    {
        return match ($this) {
            self::Green => 'bg-green-500 ring-green-100 dark:bg-green-400 dark:ring-green-950',
            self::Red => 'bg-red-500 ring-red-100 dark:bg-red-400 dark:ring-red-950',
            self::Pending => 'bg-zinc-400 ring-zinc-100 dark:bg-zinc-500 dark:ring-zinc-800',
        };
    }
}
