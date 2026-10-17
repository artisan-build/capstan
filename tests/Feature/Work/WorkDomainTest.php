<?php

use App\Enums\WorkItemState;
use App\Enums\WorkMilestoneKind;
use App\Models\Team;
use App\Models\WorkItem;
use App\Models\WorkMilestone;
use App\Models\WorkSteer;
use App\Models\WorkTask;
use App\Work\BriefRevisionService;
use App\Work\WorkItemStateService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** @param array<string, mixed> $attributes */
function workDomainTask(array $attributes = []): WorkTask
{
    return WorkTask::query()->create(array_replace([
        'team_id' => Team::default()->id,
        'title' => 'Test-created work task',
        'required_capabilities' => ['php'],
        'timebox_minutes' => 30,
        'creator_actor' => 'actor@test',
    ], $attributes));
}

test('all work records use uuid version 7 primary keys', function (): void {
    $task = workDomainTask();
    $brief = resolve(BriefRevisionService::class)->append($task, 'Test-created brief', 'author@test');
    $item = $task->items()->create(['role' => WorkItem::ROLE_ORCHESTRATOR]);
    resolve(WorkItemStateService::class)->transition($item, WorkItemState::Claimed, 'user', 'actor@test');
    $transition = $item->transitions()->sole();
    $milestone = $task->milestones()->create([
        'work_item_id' => $item->id,
        'kind' => WorkMilestoneKind::Started,
        'payload' => ['source' => 'test'],
        'author_actor' => 'author@test',
    ]);
    $steer = $task->steers()->create([
        'work_item_id' => $item->id,
        'text' => 'Test-created steer',
        'author_actor' => 'author@test',
    ]);

    foreach ([$task, $brief, $item, $transition, $milestone, $steer] as $record) {
        expect($record->id)->toBeString()
            ->and(Str::isUuid($record->id))->toBeTrue()
            ->and($record->id[14])->toBe('7')
            ->and($record->getIncrementing())->toBeFalse()
            ->and($record->getKeyType())->toBe('string');
    }
});

test('tasks reserve routing fields and items carry one open vocabulary scalar role', function (): void {
    $workflowId = (string) Str::uuid7();
    $parent = workDomainTask();
    $child = workDomainTask([
        'parent_task_id' => $parent->id,
        'workflow_id' => $workflowId,
        'user_actor_address' => 'user@server',
        'device_address' => 'device@server',
        'failover' => false,
    ]);

    $roles = [WorkItem::ROLE_ORCHESTRATOR, WorkItem::ROLE_HUMAN, 'reviewer'];
    foreach ($roles as $role) {
        $child->items()->create(['role' => $role]);
    }

    expect($child->fresh()?->parent->is($parent))->toBeTrue()
        ->and($parent->fresh()?->children->sole()->is($child))->toBeTrue()
        ->and($child->fresh()?->team->workTasks->contains($child))->toBeTrue()
        ->and($child->fresh()?->workflow_id)->toBe($workflowId)
        ->and($child->fresh()?->device_address)->toBe('device@server')
        ->and($child->fresh()?->failover)->toBeFalse()
        ->and($child->fresh()?->items->pluck('role')->all())->toBe($roles)
        ->and(Schema::hasColumn('work_items', 'role'))->toBeTrue()
        ->and(Schema::getColumnType('work_items', 'role'))->toBe('varchar')
        ->and(Schema::hasColumn('work_tasks', 'roles'))->toBeFalse()
        ->and(Schema::hasColumn('work_tasks', 'current_roles'))->toBeFalse();
});

test('team work settings have safe database and model defaults', function (): void {
    $team = Team::query()->create([
        'name' => 'Test work team',
        'slug' => 'test-work-team',
        'is_default' => false,
    ])->fresh();

    expect($team?->pool_work)->toBeFalse()
        ->and($team?->default_failover)->toBeTrue();
});

test('brief revisions append in order and newest content is canonical', function (): void {
    $task = workDomainTask();
    $briefs = resolve(BriefRevisionService::class);
    $first = $briefs->append($task, 'First test brief', 'one@test');
    $second = $briefs->append($task, 'Second test brief', 'two@test');

    expect($first->sequence)->toBe(1)
        ->and($second->sequence)->toBe(2)
        ->and($task->fresh()?->briefRevisions->pluck('content')->all())->toBe([
            'First test brief',
            'Second test brief',
        ])
        ->and($task->fresh()?->latestBriefRevision?->content)->toBe('Second test brief')
        ->and(method_exists($briefs, 'update'))->toBeFalse();

    expect(fn () => $first->update(['content' => 'Mutated']))
        ->toThrow(LogicException::class, 'append-only');

    expect($first->fresh()?->content)->toBe('First test brief');
});

test('closed work vocabularies persist as portable strings', function (): void {
    expect(array_column(WorkItemState::cases(), 'value'))
        ->toBe(['queued', 'claimed', 'delivered', 'routed', 'acted', 'failed', 'cancelled'])
        ->not->toContain('superseded')
        ->and(WorkItemState::Cancelled->isTerminal())->toBeTrue()
        ->and(array_column(WorkMilestoneKind::cases(), 'value'))->toBe([
            'started', 'blocked', 'pr_opened', 'done', 'failed', 'released', 'note',
        ])
        ->and(Schema::getColumnType('work_items', 'state'))->toBe('varchar')
        ->and(Schema::getColumnType('work_milestones', 'kind'))->toBe('varchar')
        ->and(Schema::getColumnType('work_milestones', 'payload'))->toBe('text');
});

test('last progress is bounded at the model write surface', function (): void {
    $task = workDomainTask();

    expect(fn () => $task->items()->create([
        'role' => 'builder',
        'last_progress' => str_repeat('p', WorkItem::LAST_PROGRESS_MAX_LENGTH + 1),
    ]))->toThrow(InvalidArgumentException::class, 'maximum length');

    expect($task->items()->count())->toBe(0);
});

test('work records expose their task and optional item relationships', function (): void {
    $task = workDomainTask();
    $item = $task->items()->create(['role' => 'builder']);
    $milestone = WorkMilestone::query()->create([
        'work_task_id' => $task->id,
        'work_item_id' => $item->id,
        'kind' => WorkMilestoneKind::Note,
        'payload' => ['text' => 'Test-created note'],
        'author_actor' => 'author@test',
    ]);
    $steer = WorkSteer::query()->create([
        'work_task_id' => $task->id,
        'work_item_id' => $item->id,
        'text' => 'Keep the test focused',
        'author_actor' => 'author@test',
        'read_at' => now(),
    ]);

    expect($milestone->fresh()?->workTask->is($task))->toBeTrue()
        ->and($milestone->fresh()?->workItem?->is($item))->toBeTrue()
        ->and($milestone->fresh()?->payload)->toBe(['text' => 'Test-created note'])
        ->and($steer->fresh()?->workTask->is($task))->toBeTrue()
        ->and($steer->fresh()?->workItem?->is($item))->toBeTrue()
        ->and($steer->fresh()?->read_at)->not->toBeNull();
});
