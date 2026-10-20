<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $work_task_id
 * @property string $request_key
 * @property CarbonImmutable $due_at
 * @property CarbonImmutable|null $fired_at
 * @property string $reason
 * @mixin IdeHelperWorkWake
 */
class WorkWake extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['work_task_id', 'request_key', 'due_at', 'fired_at', 'reason'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'fired_at' => 'datetime'];
    }
}
