<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

abstract class WorkAppendOnlyRecord extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        static::updating(static fn () => throw new LogicException('Orchestration history is append-only.'));
        static::deleting(static fn () => throw new LogicException('Orchestration history is append-only.'));
    }
}
