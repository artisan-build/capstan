<?php

namespace App\Enums;

enum WorkPriority: string
{
    case P0 = 'P0';
    case P1 = 'P1';
    case P2 = 'P2';
    case P3 = 'P3';

    public function isLowerThan(self $other): bool
    {
        return $this->rank() > $other->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::P0 => 0,
            self::P1 => 1,
            self::P2 => 2,
            self::P3 => 3,
        };
    }
}
