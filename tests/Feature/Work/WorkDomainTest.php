<?php

use App\Enums\WorkItemState;
use App\Enums\WorkMilestoneKind;
use App\Models\Spoke;
use App\Models\Team;
use App\Models\WorkItem;
use App\Models\WorkMilestone;
use App\Models\WorkSteer;
use App\Models\WorkTask;
use App\Work\BriefRevisionService;
use App\Work\WorkItemStateService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        'owner_actor' => 'actor@test',
        'priority' => 'P2',
    ], $attributes));
}

function workDomainSpoke(?string $actorId = null): Spoke
{
    return Spoke::query()->create([
        'actor_id' => $actorId ?? 'spoke-actor-'.Str::random(8),
        'credential_id' => (string) Str::uuid7(),
        'name' => 'Test-created Work spoke',
    ]);
}

test('all work records use uuid version 7 primary keys', function (): void {
    $task = workDomainTask();
    $brief = resolve(BriefRevisionService::class)->append($task, 'Test-created brief', 'author@test');
    $item = $task->items()->create(['role' => 'builder']);
    $spoke = workDomainSpoke('actor@test');
    $claimedAt = CarbonImmutable::now();
    $item = resolve(WorkItemStateService::class)->claim(
        $item,
        $spoke->id,
        'actor@test',
        $claimedAt->addMinutes(10),
        'spoke',
        'actor@test',
        $claimedAt,
    );
    $transition = $item->transitions()->sole();
    $milestone = $item->milestones()->create([
        'fence' => $item->fence,
        'kind' => WorkMilestoneKind::Started,
        'payload' => ['source' => 'test'],
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
    $spoke = workDomainSpoke();
    $parent = workDomainTask();
    $child = workDomainTask([
        'parent_task_id' => $parent->id,
        'workflow_ref' => 'workflows/review.yml',
        'user_actor_address' => 'user@server',
        'device_spoke_id' => $spoke->id,
        'failover' => false,
    ]);
    $urlTask = workDomainTask([
        'workflow_ref' => 'https://example.test/workflows/review.yml',
    ]);
    $databaseDefaultId = (string) Str::uuid7();
    DB::table('work_tasks')->insert([
        'id' => $databaseDefaultId,
        'team_id' => Team::default()->id,
        'title' => 'Database-default failover task',
        'required_capabilities' => '[]',
        'timebox_minutes' => 15,
        'creator_actor' => 'actor@test',
        'owner_actor' => 'actor@test',
        'priority' => 'P2',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $roles = [WorkItem::ROLE_ORCHESTRATOR, WorkItem::ROLE_HUMAN, 'reviewer'];
    foreach ($roles as $role) {
        $child->items()->create(['role' => $role]);
    }

    expect($child->fresh()?->parent->is($parent))->toBeTrue()
        ->and($parent->fresh()?->children->sole()->is($child))->toBeTrue()
        ->and($child->fresh()?->team->workTasks->contains($child))->toBeTrue()
        ->and($child->fresh()?->workflow_ref)->toBe('workflows/review.yml')
        ->and($urlTask->fresh()?->workflow_ref)->toBe('https://example.test/workflows/review.yml')
        ->and($child->fresh()?->device_spoke_id)->toBe($spoke->id)
        ->and($child->fresh()?->deviceSpoke?->is($spoke))->toBeTrue()
        ->and($child->fresh()?->failover)->toBeFalse()
        ->and($urlTask->fresh()?->failover)->toBeTrue()
        ->and((new WorkTask)->failover)->toBeTrue()
        ->and(WorkTask::query()->findOrFail($databaseDefaultId)->failover)->toBeTrue()
        ->and($child->fresh()?->items->pluck('role')->all())->toBe($roles)
        ->and(Schema::hasColumn('work_items', 'role'))->toBeTrue()
        ->and(Schema::getColumnType('work_items', 'role'))->toBe('varchar')
        ->and(Schema::hasColumn('work_tasks', 'roles'))->toBeFalse()
        ->and(Schema::hasColumn('work_tasks', 'current_roles'))->toBeFalse()
        ->and(Schema::hasColumn('work_tasks', 'workflow_id'))->toBeFalse()
        ->and(Schema::hasColumn('work_tasks', 'device_address'))->toBeFalse();

    $spoke->delete();

    expect($child->fresh()?->device_spoke_id)->toBe($spoke->id)
        ->and($child->fresh()?->deviceSpoke)->toBeNull();
});

test('children preserve explicit legacy workflow refs when the root has no immutable pin', function (): void {
    $root = workDomainTask();
    $child = workDomainTask([
        'parent_task_id' => $root->id,
        'workflow_ref' => 'workflows/review.yml',
    ]);

    expect($child->fresh()?->workflow_ref)->toBe('workflows/review.yml')
        ->and($child->fresh()?->workflow_sha)->toBeNull();

    expect(fn () => workDomainTask([
        'parent_task_id' => $root->id,
        'workflow_sha' => str_repeat('a', 40),
    ]))->toThrow(LogicException::class, 'A child workflow SHA requires a workflow reference.');
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
    $otherTask = workDomainTask(['title' => 'Other test-created work task']);
    $briefs = resolve(BriefRevisionService::class);
    $first = $briefs->append($task, 'First test brief', 'one@test');
    $second = $briefs->append($task, 'Second test brief', 'two@test');
    $briefs->append($otherTask, 'Other first brief', 'one@test');
    $briefs->append($otherTask, 'Other second brief', 'two@test');
    $otherThird = $briefs->append($otherTask, 'Other third brief', 'three@test');

    $tasks = WorkTask::query()
        ->whereKey([$task->id, $otherTask->id])
        ->with('latestBriefRevision')
        ->get()
        ->keyBy('id');

    expect($first->sequence)->toBe(1)
        ->and($second->sequence)->toBe(2)
        ->and($task->fresh()?->briefRevisions->pluck('content')->all())->toBe([
            'First test brief',
            'Second test brief',
        ])
        ->and($task->fresh()?->latestBriefRevision?->content)->toBe('Second test brief')
        ->and($otherTask->fresh()?->latestBriefRevision?->is($otherThird))->toBeTrue()
        ->and($tasks[$task->id]->latestBriefRevision?->is($second))->toBeTrue()
        ->and($tasks[$otherTask->id]->latestBriefRevision?->is($otherThird))->toBeTrue()
        ->and(method_exists($briefs, 'update'))->toBeFalse();

    expect(fn () => $first->update(['content' => 'Mutated']))
        ->toThrow(LogicException::class, 'append-only');

    expect($first->fresh()?->content)->toBe('First test brief');
});

test('closed work vocabularies persist as portable strings', function (): void {
    expect(array_column(WorkItemState::cases(), 'value'))
        ->toBe(['queued', 'claimed', 'delivered', 'routed', 'acted', 'failed', 'cancelled', 'expired'])
        ->not->toContain('superseded')
        ->and(WorkItemState::Cancelled->isTerminal())->toBeTrue()
        ->and(WorkItemState::Expired->isTerminal())->toBeTrue()
        ->and(array_column(WorkMilestoneKind::cases(), 'value'))->toBe([
            'started', 'blocked', 'pr_opened', 'done', 'failed', 'released', 'note',
        ])
        ->and(Schema::getColumnType('work_items', 'state'))->toBe('varchar')
        ->and(Schema::getColumnType('work_milestones', 'kind'))->toBe('varchar');
});

test('last progress is bounded at the model write surface', function (): void {
    $task = workDomainTask();

    expect(fn () => $task->items()->create([
        'role' => 'builder',
        'last_progress' => str_repeat('p', WorkItem::LAST_PROGRESS_MAX_LENGTH + 1),
    ]))->toThrow(InvalidArgumentException::class, 'maximum length');

    expect($task->items()->count())->toBe(0);
});

test('items resolve claimant spoke fetched brief and host wait reservations', function (): void {
    $task = workDomainTask();
    $spoke = workDomainSpoke();
    $brief = resolve(BriefRevisionService::class)->append($task, 'Fetched brief', 'author@test');
    $waitingAt = now()->subMinute()->startOfSecond();
    $notifiedAt = now()->startOfSecond();
    $item = $task->items()->create([
        'role' => 'builder',
        'claimant_spoke_id' => $spoke->id,
        'fetched_brief_revision_id' => $brief->id,
        'waiting_on_host_at' => $waitingAt,
        'waiting_on_host_notified_at' => $notifiedAt,
    ]);

    expect($item->fresh()?->claimantSpoke?->is($spoke))->toBeTrue()
        ->and($item->fresh()?->fetchedBriefRevision?->is($brief))->toBeTrue()
        ->and($item->fresh()?->waiting_on_host_at?->equalTo($waitingAt))->toBeTrue()
        ->and($item->fresh()?->waiting_on_host_notified_at?->equalTo($notifiedAt))->toBeTrue()
        ->and(Schema::hasColumn('work_items', 'waiting_on_host_at'))->toBeTrue()
        ->and(Schema::hasColumn('work_items', 'waiting_on_host_notified_at'))->toBeTrue()
        ->and(Schema::hasColumn('work_items', 'host_wait_started_at'))->toBeFalse()
        ->and(Schema::hasColumn('work_items', 'host_wait_until'))->toBeFalse()
        ->and(Schema::hasColumn('work_items', 'fetched_brief_revision'))->toBeFalse()
        ->and(Schema::hasColumn('work_items', 'claimant_spoke_address'))->toBeFalse();

    $spoke->delete();

    expect($item->fresh()?->claimant_spoke_id)->toBe($spoke->id)
        ->and($item->fresh()?->claimantSpoke)->toBeNull();
});

test('milestones require item and fence expose task scope and remain append only', function (): void {
    $task = workDomainTask();
    $item = $task->items()->create(['role' => 'builder']);
    $milestone = $item->milestones()->create([
        'fence' => 7,
        'kind' => WorkMilestoneKind::Note,
        'payload' => ['text' => 'Test-created note'],
    ]);

    expect($milestone->fresh()?->workItem->is($item))->toBeTrue()
        ->and($milestone->fresh()?->workItem->workTask->is($task))->toBeTrue()
        ->and($task->fresh()?->milestones->sole()->is($milestone))->toBeTrue()
        ->and($milestone->fresh()?->fence)->toBe(7)
        ->and($milestone->fresh()?->payload)->toBe(['text' => 'Test-created note'])
        ->and(Schema::hasColumn('work_milestones', 'work_task_id'))->toBeFalse()
        ->and(Schema::hasColumn('work_milestones', 'author_actor'))->toBeFalse();

    expect(fn () => WorkMilestone::query()->create([
        'fence' => 1,
        'kind' => WorkMilestoneKind::Note,
        'payload' => [],
    ]))->toThrow(LogicException::class, 'require an item and fence')
        ->and(fn () => $item->milestones()->create([
            'kind' => WorkMilestoneKind::Note,
            'payload' => [],
        ]))->toThrow(LogicException::class, 'require an item and fence')
        ->and(fn () => $milestone->update(['fence' => 8]))
        ->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $milestone->delete())
        ->toThrow(LogicException::class, 'append-only');

    expect($milestone->fresh()?->fence)->toBe(7)
        ->and($item->milestones()->count())->toBe(1);
});

test('steers expose their task and optional item relationships', function (): void {
    $task = workDomainTask();
    $item = $task->items()->create(['role' => 'builder']);
    $steer = WorkSteer::query()->create([
        'work_task_id' => $task->id,
        'work_item_id' => $item->id,
        'text' => 'Keep the test focused',
        'author_actor' => 'author@test',
        'read_at' => now(),
    ]);

    expect($steer->fresh()?->workTask->is($task))->toBeTrue()
        ->and($steer->fresh()?->workItem?->is($item))->toBeTrue()
        ->and($steer->fresh()?->read_at)->not->toBeNull();
});

test('the task parent foreign key is added only after the task table is created', function (): void {
    $connection = DB::connection();
    $operations = [];

    $capture = function (string $operation, string $table, Closure $callback) use ($connection, &$operations): void {
        $blueprint = new Blueprint($connection, $table);

        if ($operation === 'create') {
            $blueprint->create();
        }

        $callback($blueprint);
        $operations[] = compact('operation', 'table', 'blueprint');
    };

    Schema::shouldReceive('create')
        ->andReturnUsing(fn (string $table, Closure $callback) => $capture('create', $table, $callback));
    Schema::shouldReceive('table')
        ->andReturnUsing(fn (string $table, Closure $callback) => $capture('table', $table, $callback));

    $migration = require database_path('migrations/2026_10_17_000000_create_work_domain_tables.php');
    $migration->up();

    $workTaskOperations = collect($operations)
        ->filter(fn (array $operation): bool => $operation['table'] === 'work_tasks')
        ->values();

    expect($workTaskOperations->pluck('operation')->all())->toBe(['create', 'table']);

    /** @var Blueprint $createBlueprint */
    $createBlueprint = $workTaskOperations[0]['blueprint'];
    $parentColumn = collect($createBlueprint->getColumns())
        ->first(fn ($column): bool => $column->name === 'parent_task_id');
    $inlineParentForeign = collect($createBlueprint->getCommands())
        ->first(fn ($command): bool => $command->name === 'foreign' && $command->columns === ['parent_task_id']);

    expect($parentColumn)->not->toBeNull()
        ->and($parentColumn->type)->toBe('uuid')
        ->and($parentColumn->nullable)->toBeTrue()
        ->and($parentColumn->index)->toBeTrue()
        ->and($inlineParentForeign)->toBeNull();

    /** @var Blueprint $alterBlueprint */
    $alterBlueprint = $workTaskOperations[1]['blueprint'];
    $parentForeign = collect($alterBlueprint->getCommands())
        ->first(fn ($command): bool => $command->name === 'foreign' && $command->columns === ['parent_task_id']);

    expect($parentForeign)->not->toBeNull()
        ->and($parentForeign->references)->toBe('id')
        ->and($parentForeign->on)->toBe('work_tasks')
        ->and($parentForeign->onDelete)->toBe('set null');
});
