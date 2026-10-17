<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $work_task_id
 * @property string $work_item_id
 * @property string $request_key
 * @property string $addressed_actor
 * @property string $question
 * @property array<string, mixed> $metadata
 * @property string|null $answer
 * @property string|null $answered_by
 * @property CarbonImmutable|null $answered_at
 * @property CarbonImmutable|null $notified_at
 */
class WorkEscalation extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['work_task_id', 'work_item_id', 'request_key', 'addressed_actor', 'question', 'metadata', 'answer', 'answered_by', 'answered_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['metadata' => 'array', 'answered_at' => 'datetime', 'notified_at' => 'datetime'];
    }
}
