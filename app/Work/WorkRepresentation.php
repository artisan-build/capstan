<?php

namespace App\Work;

use App\Enums\WorkItemState;
use App\Enums\WorkMilestoneKind;
use App\Models\WorkBriefRevision;
use App\Models\WorkItem;
use App\Models\WorkMilestone;
use App\Models\WorkSteer;
use App\Models\WorkTask;
use Carbon\CarbonInterface;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\ObjectType;

final class WorkRepresentation
{
    /** @return array<string, mixed> */
    public function task(WorkTask $task): array
    {
        return $this->present($task, 'task');
    }

    /** @return array<string, mixed> */
    public function item(WorkItem $item): array
    {
        $result = $this->present($item, 'item');
        $result['stalled'] = $item->stalled_at !== null;
        $result['waiting_on_host'] = $item->waiting_on_host_at !== null;
        $latest = $item->milestones->sortByDesc('id')->first();
        if ($latest !== null) {
            $result['latest_milestone'] = $this->milestone($latest);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function milestone(WorkMilestone $milestone): array
    {
        return [...$this->present($milestone, 'milestone'), 'payload' => (object) $milestone->payload];
    }

    /** @return array<string, mixed> */
    public function revision(WorkBriefRevision $revision): array
    {
        return $this->present($revision, 'revision');
    }

    /** @return array<string, mixed> */
    public function steer(WorkSteer $steer): array
    {
        return $this->present($steer, 'steer');
    }

    /** @return array<string, mixed> */
    private function present(WorkTask|WorkItem|WorkMilestone|WorkBriefRevision|WorkSteer $model, string $kind): array
    {
        $result = [];
        foreach ($this->fields($kind) as $field => $type) {
            $value = $model->getAttribute($field);
            if ($value !== null) {
                $result[$field] = $value instanceof \BackedEnum ? $value->value
                    : ($value instanceof CarbonInterface ? $value->toJSON() : $value);
            }
        }

        return $result;
    }

    public function schema(JsonSchema $schema, string $kind): ObjectType
    {
        $properties = [];
        foreach ($this->fields($kind) as $field => $type) {
            $optional = str_ends_with($type, '?');
            $type = rtrim($type, '?');
            $property = match ($type) {
                'integer' => $schema->integer(),
                'boolean' => $schema->boolean(),
                'strings' => $schema->array()->items($schema->string()),
                default => $schema->string(),
            };
            if ($field === 'state') {
                $property = $schema->string()->enum(WorkItemState::class);
            } elseif ($field === 'kind') {
                $property = $schema->string()->enum(WorkMilestoneKind::class);
            }
            $properties[$field] = $optional ? $property : $property->required();
        }
        if ($kind === 'item') {
            $properties['stalled'] = $schema->boolean()->required();
            $properties['waiting_on_host'] = $schema->boolean()->required();
            $properties['latest_milestone'] = $this->schema($schema, 'milestone');
        } elseif ($kind === 'milestone') {
            $properties['payload'] = $schema->object([
                'url' => $schema->string(),
                'summary' => $schema->string(),
            ])->withoutAdditionalProperties()->required();
        }

        return $schema->object($properties)->withoutAdditionalProperties();
    }

    /** @return array<string, string> */
    private function fields(string $kind): array
    {
        return match ($kind) {
            'task' => [
                'id' => 'string', 'team_id' => 'integer', 'title' => 'string',
                'user_actor_address' => 'string?', 'device_spoke_id' => 'integer?',
                'failover' => 'boolean', 'required_capabilities' => 'strings',
                'timebox_minutes' => 'integer', 'creator_actor' => 'string',
                'cancelled_at' => 'string?', 'created_at' => 'string',
            ],
            'item' => [
                'id' => 'string', 'work_task_id' => 'string', 'role' => 'string', 'state' => 'string',
                'claimant_spoke_id' => 'integer?', 'claimant_actor' => 'string?', 'lease_until' => 'string?',
                'fence' => 'integer', 'attempts' => 'integer', 'last_progress' => 'string?',
                'stalled_at' => 'string?', 'waiting_on_host_at' => 'string?', 'created_at' => 'string',
            ],
            'milestone' => ['id' => 'string', 'work_item_id' => 'string', 'fence' => 'integer', 'kind' => 'string', 'created_at' => 'string'],
            'revision' => ['id' => 'string', 'work_task_id' => 'string', 'sequence' => 'integer', 'content' => 'string', 'author_actor' => 'string', 'created_at' => 'string'],
            'steer' => ['id' => 'string', 'work_task_id' => 'string', 'work_item_id' => 'string?', 'text' => 'string', 'author_actor' => 'string', 'read_at' => 'string?', 'created_at' => 'string'],
            default => throw new \LogicException('Unknown Work representation.'),
        };
    }
}
