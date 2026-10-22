<?php

use App\Enums\WorkExpirationCause;
use App\Enums\WorkExpirationProvenance;
use App\Enums\WorkItemState;
use App\Enums\WorkPriority;
use App\Http\ApiErrorException;
use App\Models\Spoke;
use App\Models\Team;
use App\Models\WorkEscalation;
use App\Models\WorkItem;
use App\Models\WorkOrchestrationEvent;
use App\Models\WorkOrchestratorSeat;
use App\Models\WorkTask;
use App\Models\WorkTaskExpiration;
use App\Work\WorkClaimService;
use App\Work\WorkDependencyService;
use App\Work\WorkItemStateService;
use App\Work\WorkOrchestrationService;
use App\Work\WorkRepresentation;
use App\Work\WorkSweepService;
use App\Work\WorkToolService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Date::setTestNow('2026-10-22 12:00:00');
});

/** @param array<string, mixed> $attributes */
function foundationTask(Team $team, array $attributes = []): WorkTask
{
    return WorkTask::query()->create(array_replace([
        'team_id' => $team->id,
        'title' => 'Foundation task '.Str::random(8),
        'required_capabilities' => [],
        'timebox_minutes' => 30,
        'creator_actor' => 'foundation-creator',
    ], $attributes));
}

function foundationSpoke(string $actor): Spoke
{
    return Spoke::query()->create([
        'actor_id' => $actor,
        'credential_id' => (string) Str::uuid7(),
    ]);
}

test('roots default ownership and descendants inherit the root owner and priority', function (): void {
    $team = Team::default();
    $root = foundationTask($team, ['creator_actor' => 'root-owner', 'priority' => WorkPriority::P1]);
    $child = foundationTask($team, [
        'parent_task_id' => $root->id,
        'creator_actor' => 'child-creator',
        'owner_actor' => 'different-owner',
        'priority' => WorkPriority::P3,
    ]);
    $grandchild = foundationTask($team, [
        'parent_task_id' => $child->id,
        'creator_actor' => 'grandchild-creator',
        'owner_actor' => 'another-owner',
        'priority' => WorkPriority::P0,
    ]);

    expect($root->owner_actor)->toBe('root-owner')
        ->and($root->priority)->toBe(WorkPriority::P1)
        ->and($child->owner_actor)->toBe('root-owner')
        ->and($child->priority)->toBe(WorkPriority::P1)
        ->and($grandchild->owner_actor)->toBe('root-owner')
        ->and($grandchild->priority)->toBe(WorkPriority::P1);
});

test('effective routing uses an override then owner routing or pooling', function (): void {
    $claims = resolve(WorkClaimService::class);
    $now = CarbonImmutable::now();
    $team = Team::query()->create(['name' => 'Foundation routing', 'slug' => 'foundation-routing', 'pool_work' => false]);
    $owner = foundationSpoke('owner');
    $other = foundationSpoke('other');

    $owned = foundationTask($team, ['owner_actor' => 'owner']);
    $ownedItem = $owned->items()->create(['role' => 'builder']);
    expect($claims->claim($team->id, 'other', $other->id, ['builder'], [], 1, $now, 300))->toBe([]);
    expect($claims->claim($team->id, 'owner', $owner->id, ['builder'], [], 1, $now, 300)[0]->id)->toBe($ownedItem->id);

    $overridden = foundationTask($team, ['owner_actor' => 'owner', 'user_actor_address' => 'other']);
    $overrideItem = $overridden->items()->create(['role' => 'reviewer']);
    expect($claims->claim($team->id, 'owner', $owner->id, ['reviewer'], [], 1, $now, 300))->toBe([]);
    expect($claims->claim($team->id, 'other', $other->id, ['reviewer'], [], 1, $now, 300)[0]->id)->toBe($overrideItem->id)
        ->and($overridden->owner_actor)->toBe('owner');

    $team->update(['pool_work' => true]);
    $pooled = foundationTask($team, ['owner_actor' => 'owner']);
    $pooledItem = $pooled->items()->create(['role' => 'pooled']);
    expect($claims->claim($team->id, 'other', $other->id, ['pooled'], [], 1, $now, 300)[0]->id)->toBe($pooledItem->id);
});

test('dependency validation is atomic and rejects invalid graph edges', function (): void {
    $team = Team::default();
    $otherTeam = Team::query()->create(['name' => 'Foreign foundation team', 'slug' => 'foreign-foundation-team']);
    $dependencies = resolve(WorkDependencyService::class);
    $task = foundationTask($team);
    $valid = foundationTask($team);
    $child = foundationTask($team, ['parent_task_id' => $task->id]);
    $foreign = foundationTask($otherTeam);

    $dependencies->replace($task, [$valid->id]);
    foreach ([
        [$task->id],
        [$child->id],
        [(string) Str::uuid7()],
        [$foreign->id],
        [$valid->id, (string) Str::uuid7()],
    ] as $invalid) {
        expect(fn () => $dependencies->replace($task, $invalid))
            ->toThrow(ApiErrorException::class, 'Work dependencies are invalid.');
        expect($task->dependencies()->pluck('work_tasks.id')->all())->toBe([$valid->id]);
    }

    $cycleA = foundationTask($team);
    $cycleB = foundationTask($team);
    $dependencies->replace($cycleA, [$cycleB->id]);
    expect(fn () => $dependencies->replace($cycleB, [$cycleA->id]))
        ->toThrow(ApiErrorException::class, 'Work dependencies are invalid.')
        ->and($cycleB->dependencies()->count())->toBe(0);
});

test('dependencies gate real claims until every dependency item is acted', function (): void {
    $team = Team::query()->create(['name' => 'Dependency gate', 'slug' => 'dependency-gate', 'pool_work' => false]);
    $spoke = foundationSpoke('worker');
    $dependency = foundationTask($team, ['owner_actor' => 'worker']);
    $dependencyItem = $dependency->items()->create(['role' => 'dependency-builder']);
    $dependent = foundationTask($team, ['owner_actor' => 'worker']);
    resolve(WorkDependencyService::class)->replace($dependent, [$dependency->id]);
    $dependentItem = $dependent->items()->create(['role' => 'builder']);
    $claims = resolve(WorkClaimService::class);
    $now = CarbonImmutable::now();

    expect($claims->claim($team->id, 'worker', $spoke->id, ['builder'], [], 1, $now, 300))->toBe([]);

    $states = resolve(WorkItemStateService::class);
    $claimed = $states->claim($dependencyItem, $spoke->id, 'worker', $now->addMinutes(5), 'spoke', 'worker', $now);
    $delivered = $states->transition($claimed, WorkItemState::Delivered, 'spoke', 'worker', $now);
    $routed = $states->transition($delivered, WorkItemState::Routed, 'spoke', 'worker', $now);
    $states->transition($routed, WorkItemState::Acted, 'spoke', 'worker', $now);

    expect($claims->claim($team->id, 'worker', $spoke->id, ['builder'], [], 1, $now, 300)[0]->id)->toBe($dependentItem->id);
});

test('dependency invalidation before the locked gate prevents a stale claim', function (string $invalidation): void {
    $team = Team::query()->create(['name' => 'Dependency invalidation', 'slug' => 'dependency-invalidation', 'pool_work' => false]);
    $spoke = foundationSpoke('worker');
    $dependency = foundationTask($team, ['owner_actor' => 'worker']);
    $dependency->items()->create(['role' => 'dependency-builder', 'state' => WorkItemState::Acted]);
    $dependent = foundationTask($team, ['owner_actor' => 'worker']);
    resolve(WorkDependencyService::class)->replace($dependent, [$dependency->id]);
    $dependent->items()->create(['role' => 'builder']);
    $unfinished = foundationTask($team, ['owner_actor' => 'worker']);
    $unfinished->items()->create(['role' => 'dependency-builder']);

    $claimed = resolve(WorkClaimService::class)->claim(
        $team->id,
        'worker',
        $spoke->id,
        ['builder'],
        [],
        1,
        CarbonImmutable::now(),
        300,
        afterCandidates: function () use ($invalidation, $team, $dependency, $dependent, $unfinished): void {
            if ($invalidation === 'relation') {
                resolve(WorkDependencyService::class)->replace($dependent, [$unfinished->id]);

                return;
            }
            resolve(WorkToolService::class)->write(
                $invalidation === 'cancel' ? 'cancel_task' : 'queue_item',
                [
                    'team_id' => $team->id,
                    'task_id' => $dependency->id,
                    ...($invalidation === 'queue' ? ['role' => 'reviewer'] : []),
                ],
                'worker',
            );
        },
    );

    expect($claimed)->toBe([])
        ->and($dependent->items()->where('role', 'builder')->sole()->state)->toBe(WorkItemState::Queued);
})->with(['cancel', 'queue', 'relation']);

test('a dependency completed before expiry stays successful after its deadline', function (): void {
    $team = Team::query()->create(['name' => 'Completed dependency expiry', 'slug' => 'completed-dependency-expiry', 'pool_work' => false]);
    $spoke = foundationSpoke('worker');
    $dependency = foundationTask($team, ['owner_actor' => 'worker', 'expires_at' => now()->addMinute()]);
    $dependency->items()->create(['role' => 'dependency-builder', 'state' => WorkItemState::Acted]);
    $dependent = foundationTask($team, ['owner_actor' => 'worker']);
    resolve(WorkDependencyService::class)->replace($dependent, [$dependency->id]);
    $item = $dependent->items()->create(['role' => 'builder']);
    Date::setTestNow(now()->addMinutes(2));

    resolve(WorkSweepService::class)->expiration(CarbonImmutable::now());
    $claimed = resolve(WorkClaimService::class)->claim(
        $team->id, 'worker', $spoke->id, ['builder'], [], 1, CarbonImmutable::now(), 300,
    );

    expect($claimed[0]->id)->toBe($item->id)
        ->and($dependency->expiration()->doesntExist())->toBeTrue()
        ->and(WorkOrchestrationEvent::query()->where('kind', 'dependency_failed')->count())->toBe(0)
        ->and(WorkEscalation::query()->count())->toBe(0);
});

test('a failed dependency signals the root owner once without cancelling the dependent', function (): void {
    $user = capstanUser();
    $team = Team::default();
    $dependency = foundationTask($team, ['owner_actor' => (string) $user->id]);
    $dependent = foundationTask($team, ['owner_actor' => (string) $user->id]);
    resolve(WorkDependencyService::class)->replace($dependent, [$dependency->id]);
    $failed = $dependency->items()->create(['role' => 'builder', 'state' => WorkItemState::Routed]);

    resolve(WorkItemStateService::class)->transition($failed, WorkItemState::Failed, 'spoke', 'worker');
    resolve(WorkDependencyService::class)->signalFailed($dependency);

    expect($dependent->fresh()->cancelled_at)->toBeNull()
        ->and(WorkEscalation::query()->where('work_task_id', $dependent->id)->count())->toBe(1)
        ->and($dependent->items()->where('role', WorkItem::ROLE_HUMAN)->sole()->addressed_actor)->toBe((string) $user->id);
});

test('a failed dependency wakes an existing root orchestrator once', function (): void {
    $team = Team::default();
    $dependency = foundationTask($team);
    $dependent = foundationTask($team);
    resolve(WorkDependencyService::class)->replace($dependent, [$dependency->id]);
    resolve(WorkOrchestrationService::class)->event($dependent, 'existing-orchestrator', 'test_event');
    $failed = $dependency->items()->create(['role' => 'builder', 'state' => WorkItemState::Routed]);

    resolve(WorkItemStateService::class)->transition($failed, WorkItemState::Failed, 'spoke', 'worker');
    resolve(WorkDependencyService::class)->signalFailed($dependency);

    expect(WorkOrchestrationEvent::query()->where('work_task_id', $dependent->id)->where('kind', 'dependency_failed')->count())->toBe(1)
        ->and(WorkEscalation::query()->where('work_task_id', $dependent->id)->count())->toBe(0)
        ->and($dependent->fresh()->cancelled_at)->toBeNull();
});

test('a failed dependency with a stopped root seat signals the owner once', function (): void {
    $user = capstanUser();
    $team = Team::default();
    $dependency = foundationTask($team, ['owner_actor' => (string) $user->id]);
    $dependent = foundationTask($team, ['owner_actor' => (string) $user->id]);
    resolve(WorkDependencyService::class)->replace($dependent, [$dependency->id]);
    WorkOrchestratorSeat::query()->create(['work_task_id' => $dependent->id, 'stopped_at' => now()]);
    $failed = $dependency->items()->create(['role' => 'builder', 'state' => WorkItemState::Routed]);

    resolve(WorkItemStateService::class)->transition($failed, WorkItemState::Failed, 'spoke', 'worker');
    resolve(WorkDependencyService::class)->signalFailed($dependency);

    expect(WorkOrchestrationEvent::query()->where('work_task_id', $dependent->id)->where('kind', 'dependency_failed')->count())->toBe(0)
        ->and(WorkEscalation::query()->where('work_task_id', $dependent->id)->count())->toBe(1)
        ->and($dependent->items()->where('role', WorkItem::ROLE_HUMAN)->sole()->addressed_actor)->toBe((string) $user->id);
});

test('claims use priority then UUIDv7 FIFO and active P0 roots are unique per owner', function (): void {
    $team = Team::query()->create(['name' => 'Priority queue', 'slug' => 'priority-queue', 'pool_work' => false]);
    $spoke = foundationSpoke('priority-owner');
    $expected = [];
    foreach ([WorkPriority::P3, WorkPriority::P1, WorkPriority::P2, WorkPriority::P0] as $priority) {
        $task = foundationTask($team, ['owner_actor' => 'priority-owner', 'priority' => $priority]);
        $expected[$priority->value][] = $task->items()->create(['role' => 'builder'])->id;
    }
    $p2 = WorkTask::query()->where('priority', WorkPriority::P2->value)->firstOrFail();
    $expected['P2'][] = $p2->items()->create(['role' => 'builder'])->id;
    sort($expected['P2']);

    $claimed = resolve(WorkClaimService::class)->claim(
        $team->id, 'priority-owner', $spoke->id, ['builder'], [], 5, CarbonImmutable::now(), 300,
    );
    expect(array_column($claimed, 'id'))->toBe([
        ...$expected['P0'], ...$expected['P1'], ...$expected['P2'], ...$expected['P3'],
    ]);

    $otherOwner = foundationTask($team, ['owner_actor' => 'other-priority-owner', 'priority' => WorkPriority::P0]);
    expect($otherOwner->priority)->toBe(WorkPriority::P0);

    expect(fn () => foundationTask($team, ['owner_actor' => 'priority-owner', 'priority' => WorkPriority::P0]))
        ->toThrow(QueryException::class);
});

test('own and ancestor expiry block claims and sweeps expire queued items exactly once', function (): void {
    $team = Team::query()->create(['name' => 'Expiry gate', 'slug' => 'expiry-gate', 'pool_work' => false]);
    $spoke = foundationSpoke('expiry-owner');
    $root = foundationTask($team, ['owner_actor' => 'expiry-owner', 'expires_at' => now()->subMinute()]);
    $queued = $root->items()->create(['role' => 'builder']);
    $running = $root->items()->create(['role' => 'runner', 'state' => WorkItemState::Routed]);
    expect(resolve(WorkClaimService::class)->claim(
        $team->id, 'expiry-owner', $spoke->id, ['builder'], [], 1, CarbonImmutable::now(), 300,
    ))->toBe([]);

    $sweep = resolve(WorkSweepService::class);
    $nested = false;
    $sweep->expiration(CarbonImmutable::now(), function () use ($sweep, &$nested): void {
        if (! $nested) {
            $nested = true;
            $sweep->expiration(CarbonImmutable::now());
        }
    });
    $sweep->expiration(CarbonImmutable::now());

    $record = WorkTaskExpiration::query()->where('work_task_id', $root->id)->sole();
    expect($queued->fresh()->state)->toBe(WorkItemState::Expired)
        ->and($running->fresh()->state)->toBe(WorkItemState::Routed)
        ->and($queued->transitions()->where('to_state', WorkItemState::Expired)->count())->toBe(1)
        ->and(WorkTaskExpiration::query()->where('work_task_id', $root->id)->count())->toBe(1)
        ->and($record->id[14])->toBe('7')
        ->and($record->provenance)->toBe(WorkExpirationProvenance::Own)
        ->and($record->cause)->toBe(WorkExpirationCause::Unclaimed)
        ->and($record->owner_actor)->toBe('expiry-owner')
        ->and($record->priority)->toBe(WorkPriority::P2)
        ->and($record->waited_seconds)->toBeGreaterThanOrEqual(0);

    $ancestor = foundationTask($team, ['owner_actor' => 'expiry-owner', 'expires_at' => now()->subMinute()]);
    $child = foundationTask($team, ['parent_task_id' => $ancestor->id, 'expires_at' => now()->addHour()]);
    $childItem = $child->items()->create(['role' => 'child-builder']);
    expect(resolve(WorkClaimService::class)->claim(
        $team->id, 'expiry-owner', $spoke->id, ['child-builder'], [], 1, CarbonImmutable::now(), 300,
    ))->toBe([]);
    $sweep->expiration(CarbonImmutable::now());
    expect($childItem->fresh()->state)->toBe(WorkItemState::Expired)
        ->and($child->expiration()->sole()->provenance)->toBe(WorkExpirationProvenance::Ancestor)
        ->and($child->expiration()->sole()->cause)->toBe(WorkExpirationCause::Unclaimed);
});

test('expiry records an unmet dependency as the never-ran cause', function (): void {
    $team = Team::default();
    $dependency = foundationTask($team);
    $dependency->items()->create(['role' => 'builder']);
    $task = foundationTask($team, ['expires_at' => now()->subMinute()]);
    resolve(WorkDependencyService::class)->replace($task, [$dependency->id]);
    $task->items()->create(['role' => 'reviewer']);

    resolve(WorkSweepService::class)->expiration(CarbonImmutable::now());

    expect($task->expiration()->sole()->cause)->toBe(WorkExpirationCause::DependencyUnmet)
        ->and(array_column(WorkExpirationCause::cases(), 'value'))->toBe([
            'dependency_unmet', 'unclaimed', 'no_eligible_machine', 'preempted',
        ]);
});

test('task representations expose required orchestration fields and omit unset optionals', function (): void {
    $team = Team::default();
    $dependency = foundationTask($team);
    $task = foundationTask($team, ['owner_actor' => 'represented-owner', 'priority' => WorkPriority::P3]);
    resolve(WorkDependencyService::class)->replace($task, [$dependency->id]);
    $represented = resolve(WorkRepresentation::class)->task($task);

    expect($represented['owner_actor'])->toBe('represented-owner')
        ->and($represented['priority'])->toBe('P3')
        ->and($represented['depends_on'])->toBe([$dependency->id])
        ->and($represented)->not->toHaveKey('expires_at')
        ->and($represented)->not->toHaveKey('pr');
});
