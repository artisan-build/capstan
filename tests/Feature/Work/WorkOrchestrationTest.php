<?php

use App\Enums\WorkItemState;
use App\Enums\WorkMilestoneKind;
use App\Http\ApiErrorException;
use App\Models\Spoke;
use App\Models\Team;
use App\Models\WorkEscalation;
use App\Models\WorkItem;
use App\Models\WorkOrchestrationDecision;
use App\Models\WorkOrchestrationEvent;
use App\Models\WorkOrchestratorSeat;
use App\Models\WorkStateRevision;
use App\Models\WorkTask;
use App\Work\LogWorkNotifier;
use App\Work\WorkClaimService;
use App\Work\WorkItemStateService;
use App\Work\WorkNotifier;
use App\Work\WorkOrchestrationAuthor;
use App\Work\WorkOrchestrationService;
use App\Work\WorkSeatService;
use App\Work\WorkSweepService;
use App\Work\WorkToolService;
use App\Work\WorkWorkerService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config([
        'capstan.features.work' => true, 'capstan.work.orchestration.turn_budget' => 20,
        'capstan.work.orchestration.child_budget' => 100, 'capstan.work.orchestration.attempt_cap' => 3,
    ]);
    Feature::flushCache();
    Date::setTestNow('2026-10-17 12:00:00');
});

function orchestrationTask(?WorkTask $parent = null): WorkTask
{
    return WorkTask::query()->create([
        'team_id' => $parent?->team_id ?? Team::default()->id,
        'parent_task_id' => $parent?->id, 'title' => 'Engine '.Str::random(8),
        'required_capabilities' => [], 'timebox_minutes' => 10, 'creator_actor' => 'supervisor',
    ]);
}

function orchestrationHost(string $actor = 'host-a'): Spoke
{
    return Spoke::query()->create(['actor_id' => $actor, 'credential_id' => (string) Str::uuid7(), 'last_polled_at' => now()]);
}

/** @return list<WorkItem> */
function orchestrationClaims(WorkTask $parent, Spoke $host, array $roles = ['orchestrator']): array
{
    return resolve(WorkClaimService::class)->claim($parent->team_id, $host->actor_id, $host->id, $roles, [], 25, CarbonImmutable::now(), 300);
}

function orchestrationSeat(WorkTask $parent): WorkOrchestratorSeat
{
    return WorkOrchestratorSeat::query()->where('work_task_id', $parent->id)->sole();
}

function orchestrationEvent(WorkTask $parent, string $key = 'event'): WorkOrchestrationEvent
{
    return resolve(WorkOrchestrationService::class)->event($parent, $key, 'test_fact', ['marker' => $key]);
}

test('five mixed source facts coalesce durably and own subtree writes never self wake', function (): void {
    $engine = resolve(WorkOrchestrationService::class);
    $parent = orchestrationTask();
    $child = orchestrationTask($parent);
    $host = orchestrationHost();
    $states = resolve(WorkItemStateService::class);
    foreach ([WorkItemState::Acted, WorkItemState::Failed, WorkItemState::Cancelled] as $state) {
        $states->transition($child->items()->create(['role' => 'builder', 'state' => 'routed']), $state, 'spoke', 'external');
    }
    $blocked = $child->items()->create(['role' => 'builder', 'state' => 'routed', 'fence' => 1, 'claimant_actor' => $host->actor_id, 'claimant_spoke_id' => $host->id, 'lease_until' => now()->addHour()]);
    resolve(WorkWorkerService::class)->milestone($blocked->id, 1, WorkMilestoneKind::Blocked, ['summary' => Str::random()]);
    $stalled = $child->items()->create(['role' => 'builder', 'state' => 'routed', 'fence' => 1, 'claimant_actor' => $host->actor_id, 'claimant_spoke_id' => $host->id, 'claimed_at' => now()->subMinutes(11), 'lease_until' => now()->addHour()]);
    resolve(WorkSweepService::class)->liveness(CarbonImmutable::now());
    resolve(WorkSweepService::class)->liveness(CarbonImmutable::now());
    expect(WorkOrchestrationEvent::query()->count())->toBe(5)
        ->and($parent->items()->where('role', 'orchestrator')->count())->toBe(1);
    $turn = orchestrationClaims($parent, $host)[0];
    expect($engine->turn($turn)['events'])->toHaveCount(5)
        ->and(array_column($engine->turn($turn)['events'], 'kind'))->toBe(['child_acted', 'child_failed', 'child_cancelled', 'child_blocked', 'child_stalled']);
    $own = $child->items()->create(['role' => 'builder']);
    $engine->authored($turn, $turn->fence, $turn->seat_fence, CarbonImmutable::now(), fn () => $states->transition($own, WorkItemState::Cancelled, 'orchestrator', $parent->id));
    expect(WorkOrchestrationEvent::query()->where('wakes', false)->count())->toBe(1);
    expect(WorkOrchestrationEvent::query()->where('wakes', false)->sole()->author_task_id)->toBe($parent->id)
        ->and(Str::isUuid(WorkOrchestrationEvent::query()->where('wakes', false)->sole()->author_task_id))->toBeTrue();
    $engine->turnDone($turn, $turn->fence, $turn->seat_fence, 'Completed batch', CarbonImmutable::now());
    expect(orchestrationSeat($parent)->completed_cursor)->toBe(5)
        ->and($parent->items()->where('state', 'queued')->count())->toBe(0)
        ->and($stalled->fresh()->stalled_at)->not->toBeNull();
});

test('events during a live turn survive the immutable batch and queue one later turn', function (): void {
    $engine = resolve(WorkOrchestrationService::class);
    $parent = orchestrationTask();
    orchestrationEvent($parent, 'first');
    $host = orchestrationHost();
    $turn = orchestrationClaims($parent, $host)[0];
    foreach (range(2, 6) as $index) {
        orchestrationEvent($parent, "later-$index");
    }
    expect($engine->turn($turn)['events'])->toHaveCount(1)
        ->and(orchestrationClaims($parent, $host))->toBe([])
        ->and($parent->items()->count())->toBe(1);
    $engine->turnDone($turn, 1, $turn->seat_fence, 'First state', CarbonImmutable::now());
    $next = orchestrationClaims($parent, $host)[0];
    expect($engine->turn($next)['events'])->toHaveCount(5)
        ->and($next->turn_cursor)->toBe(1)->and($next->turn_upper_bound)->toBe(6)
        ->and($parent->items()->whereIn('state', ['queued', 'claimed', 'delivered', 'routed'])->count())->toBe(1);
});

test('holder affinity preserves opaque session refs every turn and interactive seats exclude drains', function (): void {
    $parent = orchestrationTask();
    $a = orchestrationHost();
    $b = orchestrationHost('host-b');
    $seats = resolve(WorkSeatService::class);
    $ref = "opaque://Session/Exact ? Case=YES\n".Str::random();
    $seat = $seats->take($parent, $a->actor_id, $a->id, 0, $ref, false, CarbonImmutable::now());
    foreach (range(1, 2) as $index) {
        orchestrationEvent($parent, "turn-$index");
        expect(orchestrationClaims($parent, $b))->toBe([]);
        $turn = orchestrationClaims($parent, $a)[0];
        expect($turn->session_ref)->toBe($ref)->and($turn->seat_fence)->toBe($seat->fence);
        resolve(WorkOrchestrationService::class)->turnDone($turn, $turn->fence, $seat->fence, "State $index", CarbonImmutable::now());
    }
    $seat = $seats->take($parent, $a->actor_id, $a->id, $seat->fence, $ref, true, CarbonImmutable::now());
    orchestrationEvent($parent, 'interactive');
    expect(orchestrationClaims($parent, $a))->toBe([])->and(orchestrationClaims($parent, $b))->toBe([]);
    $released = $seats->release($parent, $a->actor_id, $a->id, $seat->fence, CarbonImmutable::now());
    expect($released->fence)->toBeGreaterThan($seat->fence);
    expect(fn () => $seats->renew($parent, $a->actor_id, $a->id, $seat->fence, 'stale', CarbonImmutable::now()))->toThrow(DomainException::class);
    expect(orchestrationClaims($parent, $b))->toHaveCount(1);
});

test('routing does not complete a turn and both fences guard atomic turn_done', function (): void {
    $parent = orchestrationTask();
    orchestrationEvent($parent);
    $host = orchestrationHost();
    $turn = orchestrationClaims($parent, $host)[0];
    $states = resolve(WorkItemStateService::class);
    $turn = $states->transition($turn, WorkItemState::Delivered, 'spoke', $host->actor_id);
    $turn = $states->transition($turn, WorkItemState::Routed, 'spoke', $host->actor_id);
    expect($turn->state)->toBe(WorkItemState::Routed)->and(orchestrationSeat($parent)->completed_cursor)->toBe(0);
    expect(fn () => $states->transition($turn, WorkItemState::Acted, 'spoke', $host->actor_id))->toThrow(DomainException::class, 'turn_done');
    $engine = resolve(WorkOrchestrationService::class);
    foreach ([[99, $turn->seat_fence], [$turn->fence, 99]] as [$itemFence, $seatFence]) {
        expect(fn () => $engine->turnDone($turn, $itemFence, $seatFence, 'Must not persist', CarbonImmutable::now()))->toThrow(DomainException::class);
        expect(WorkStateRevision::query()->count())->toBe(0)->and(orchestrationSeat($parent)->completed_cursor)->toBe(0)
            ->and($turn->fresh()->state)->toBe(WorkItemState::Routed)->and($turn->transitions()->count())->toBe(3);
    }
    $revision = $engine->turnDone($turn, $turn->fence, $turn->seat_fence, 'Durable state '.Str::random(), CarbonImmutable::now());
    expect($revision->through_cursor)->toBe(1)->and($turn->fresh()->state)->toBe(WorkItemState::Acted)
        ->and(orchestrationSeat($parent)->turns_completed)->toBe(1);
    expect(fn () => $engine->turnDone($turn, $turn->fence, $turn->seat_fence, 'Duplicate', CarbonImmutable::now()))->toThrow(DomainException::class);
    expect(WorkStateRevision::query()->count())->toBe(1);
});

test('pinned parent failover transfers and fences a held turn without losing memory or failing back', function (bool $sweep): void {
    $engine = resolve(WorkOrchestrationService::class);
    $parent = orchestrationTask();
    $a = orchestrationHost();
    $b = orchestrationHost('host-b');
    $parent->update(['device_spoke_id' => $a->id, 'failover' => true]);
    $child = orchestrationTask($parent);
    $child->update(['device_spoke_id' => $a->id, 'failover' => false]);
    $leaf = $child->items()->create(['role' => 'builder']);
    $event = orchestrationEvent($parent);
    expect(orchestrationClaims($parent, $b, ['orchestrator', 'builder']))->toBe([]);
    $turn = orchestrationClaims($parent, $a)[0];
    $decision = $engine->decide($turn, 1, $turn->seat_fence, $event->id, 'Retry', 'Evidence supports retry', ['proof' => Str::random()], CarbonImmutable::now());
    $engine->turnDone($turn, 1, $turn->seat_fence, 'First revision', CarbonImmutable::now());
    orchestrationEvent($parent, 'second');
    $held = orchestrationClaims($parent, $a)[0];
    Date::setTestNow(now()->addSeconds(301));
    if ($sweep) {
        $this->artisan('work:orchestration-sweep')->assertSuccessful();
    } else {
        resolve(WorkItemStateService::class)->expireLease($held, 'system', 'expiry', CarbonImmutable::now());
    }
    $replacement = orchestrationClaims($parent, $b, ['orchestrator', 'builder'])[0];
    expect($parent->fresh()->device_spoke_id)->toBe($a->id)
        ->and($child->fresh()->device_spoke_id)->toBe($a->id)
        ->and($leaf->fresh()->state)->toBe(WorkItemState::Queued);
    expect($replacement->id)->toBe($held->id)->and($replacement->fence)->toBeGreaterThan($held->fence)
        ->and($replacement->seat_fence)->toBeGreaterThan($held->seat_fence)
        ->and($replacement->session_ref)->toBeNull()
        ->and(WorkOrchestrationDecision::query()->sole()->id)->toBe($decision->id)
        ->and(WorkStateRevision::query()->count())->toBe(1)
        ->and(WorkOrchestrationEvent::query()->count())->toBe(2)
        ->and(orchestrationSeat($parent)->completed_cursor)->toBe(1);
    expect(fn () => $engine->turnDone($held, $held->fence, $held->seat_fence, 'stale', CarbonImmutable::now()))->toThrow(DomainException::class);
    expect(fn () => resolve(WorkSeatService::class)->renew($parent, $a->actor_id, $a->id, $held->seat_fence, 'stale', CarbonImmutable::now()))->toThrow(DomainException::class);
    $engine->turnDone($replacement, $replacement->fence, $replacement->seat_fence, 'Replacement state', CarbonImmutable::now());
    orchestrationEvent($parent, 'third');
    expect(orchestrationClaims($parent, $a))->toBe([])->and(orchestrationClaims($parent, $b))->toHaveCount(1);
})->with(['seat sweep' => true, 'claim takeover' => false]);

test('non failover seat waits and notifies once then resumes only its holder', function (): void {
    $parent = orchestrationTask();
    $parent->update(['failover' => false]);
    $a = orchestrationHost();
    $b = orchestrationHost('host-b');
    $seats = resolve(WorkSeatService::class);
    $seat = $seats->take($parent, $a->actor_id, $a->id, 0, 'exact-session', false, CarbonImmutable::now(), 1);
    orchestrationEvent($parent);
    Date::setTestNow(now()->addSeconds(2));
    $notifier = Mockery::mock(WorkNotifier::class);
    $notifier->shouldReceive('seatWaiting')->once()->withArgs(fn (WorkOrchestratorSeat $arg): bool => $arg->id === $seat->id);
    app()->instance(WorkNotifier::class, $notifier);
    $seats = resolve(WorkSeatService::class);
    $seats->sweep(CarbonImmutable::now());
    $seats->sweep(CarbonImmutable::now());
    expect(orchestrationSeat($parent)->waiting_at)->not->toBeNull()
        ->and(orchestrationClaims($parent, $b))->toBe([]);
    expect(fn () => $seats->take($parent, $b->actor_id, $b->id, $seat->fence, null, false, CarbonImmutable::now()))->toThrow(DomainException::class);
    $turn = orchestrationClaims($parent, $a)[0];
    expect($turn->session_ref)->toBe('exact-session')->and(orchestrationSeat($parent)->waiting_at)->toBeNull()
        ->and(orchestrationSeat($parent)->notified_at)->toBeNull();
});

test('due wakes and addressed human answers are idempotent durable events', function (): void {
    $engine = resolve(WorkOrchestrationService::class);
    $parent = orchestrationTask();
    $wake = $engine->requestWake($parent, 'check', CarbonImmutable::now(), 'Check result');
    expect($engine->requestWake($parent, 'check', CarbonImmutable::now()->addHour(), 'Duplicate')->id)->toBe($wake->id);
    $future = $engine->requestWake($parent, 'later', CarbonImmutable::now()->addHour(), 'Future');
    $options = ['Continue exactly / CASE', "Stop\nfor review"];
    $recommendation = 'Continue exactly / CASE';
    $notifier = Mockery::mock(WorkNotifier::class);
    $notifier->shouldReceive('escalated')->once()->withArgs(fn (WorkEscalation $arg): bool => $arg->request_key === 'question' && $arg->notified_at !== null);
    app()->instance(WorkNotifier::class, $notifier);
    $human = $engine->escalate($parent, 'question', 'supervisor', 'Which direction?', $options, $recommendation);
    expect(resolve(WorkOrchestrationService::class)->escalate($parent, 'question', 'supervisor', 'Duplicate', ['Different'], 'Different')->id)->toBe($human->id);
    expect($human->fresh()->question)->toBe('Which direction?')
        ->and($human->fresh()->metadata)->toBe(['options' => $options, 'recommendation' => $recommendation])
        ->and($human->fresh()->notified_at)->not->toBeNull()
        ->and($parent->items()->where('role', 'human')->count())->toBe(1);
    $host = orchestrationHost();
    expect(orchestrationClaims($parent, $host, ['human', 'orchestrator']))->toBe([]);
    expect(fn () => $engine->answer($human, 'other', 'Wrong actor', CarbonImmutable::now()))->toThrow(DomainException::class);
    WorkOrchestrationAuthor::run($parent->id, fn () => $engine->answer($human, 'supervisor', 'Continue', CarbonImmutable::now()));
    $engine->answer($human, 'supervisor', 'Duplicate', CarbonImmutable::now());
    WorkOrchestrationAuthor::run($parent->id, fn () => $engine->dueWakes(CarbonImmutable::now()));
    $engine->dueWakes(CarbonImmutable::now());
    expect($human->fresh()->answer)->toBe('Continue')->and($wake->fresh()->fired_at)->not->toBeNull()
        ->and($future->fresh()->fired_at)->toBeNull()->and(WorkOrchestrationEvent::query()->count())->toBe(2)
        ->and($parent->items()->where('role', 'orchestrator')->count())->toBe(1)
        ->and(orchestrationClaims($parent, $host, ['human', 'orchestrator']))->toHaveCount(1);
    foreach (WorkOrchestrationEvent::query()->get() as $event) {
        expect($event->author_task_id)->toBeNull()->and($event->wakes)->toBeTrue();
    }
    expect(WorkOrchestrationEvent::query()->pluck('kind')->all())->toBe(['escalation_answered', 'wake_due']);
});

test('attempt cap projection is atomic idempotent and includes all ancestors', function (): void {
    $root = orchestrationTask();
    $parent = orchestrationTask($root);
    $child = orchestrationTask($parent);
    $host = orchestrationHost();
    $item = $child->items()->create(['role' => 'builder', 'state' => 'routed', 'fence' => 1, 'attempts' => 2, 'lease_until' => now()->subSecond(), 'claimed_at' => now()->subMinute(), 'claimant_actor' => $host->actor_id, 'claimant_spoke_id' => $host->id]);
    DB::beginTransaction();
    resolve(WorkItemStateService::class)->expireLease($item, 'system', 'expiry', CarbonImmutable::now());
    expect(WorkOrchestrationEvent::query()->count())->toBe(2);
    DB::rollBack();
    expect(WorkOrchestrationEvent::query()->count())->toBe(0)->and($item->fresh()->attempts)->toBe(2);
    resolve(WorkSweepService::class)->liveness(CarbonImmutable::now());
    resolve(WorkSweepService::class)->liveness(CarbonImmutable::now());
    expect(WorkOrchestrationEvent::query()->where('kind', 'child_attempt_cap')->count())->toBe(2)
        ->and($root->items()->where('role', 'orchestrator')->count())->toBe(1)
        ->and($parent->items()->where('role', 'orchestrator')->count())->toBe(1);
});

test('ordinary queue writes reject reserved roles without creating items', function (string $role): void {
    $parent = orchestrationTask();
    $input = ['team_id' => $parent->team_id, 'task_id' => $parent->id, 'role' => $role];
    foreach (range(1, 2) as $attempt) {
        expect(fn () => resolve(WorkToolService::class)->write('queue_item', $input, 'supervisor'))
            ->toThrow(ApiErrorException::class);
        expect($parent->items()->count())->toBe(0)->and(WorkEscalation::query()->count())->toBe(0);
    }
    resolve(WorkToolService::class)->write('queue_item', array_replace($input, ['role' => 'custom_review']), 'supervisor');
    expect($parent->items()->sole()->role)->toBe('custom_review');
})->with(['orchestrator', 'human']);

test('first answers require a live queued addressed human item without partial effects', function (string $invalid): void {
    $engine = resolve(WorkOrchestrationService::class);
    $parent = orchestrationTask();
    $human = $engine->escalate($parent, 'guard', 'supervisor', 'Question', ['Continue'], 'Continue');
    $item = WorkItem::query()->findOrFail($human->work_item_id);
    match ($invalid) {
        'cancel_item' => resolve(WorkToolService::class)->write('cancel_item', ['team_id' => $parent->team_id, 'item_id' => $item->id], 'supervisor'),
        'cancel_task' => resolve(WorkToolService::class)->write('cancel_task', ['team_id' => $parent->team_id, 'task_id' => $parent->id], 'supervisor'),
        'task_only' => $parent->forceFill(['cancelled_at' => now()])->save(),
        'role' => $item->forceFill(['role' => 'builder'])->save(),
        'actor' => $item->forceFill(['addressed_actor' => 'other'])->save(),
        'task' => $item->forceFill(['work_task_id' => orchestrationTask()->id])->save(),
        'acted' => $item->forceFill(['state' => WorkItemState::Acted])->save(),
    };
    $tables = ['work_escalations', 'work_items', 'work_item_transitions', 'work_orchestration_events', 'work_orchestrator_seats'];
    $snapshot = fn () => array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
    $before = $snapshot();
    expect(fn () => $engine->answer($human, 'supervisor', 'Rejected', CarbonImmutable::now()))->toThrow(DomainException::class);
    expect($snapshot())->toBe($before)->and($human->fresh()->answer)->toBeNull()
        ->and($human->fresh()->answered_at)->toBeNull()->and($human->fresh()->answered_by)->toBeNull();
})->with(['cancel_item', 'cancel_task', 'task_only', 'role', 'actor', 'task', 'acted']);

test('accepted answers replay only for the addressee even after task cancellation', function (): void {
    $engine = resolve(WorkOrchestrationService::class);
    $parent = orchestrationTask();
    $human = $engine->escalate($parent, 'replay', 'supervisor', 'Question', ['Continue'], 'Continue');
    $engine->answer($human, 'supervisor', 'Accepted', CarbonImmutable::now());
    resolve(WorkToolService::class)->write('cancel_task', ['team_id' => $parent->team_id, 'task_id' => $parent->id], 'supervisor');
    $item = WorkItem::query()->findOrFail($human->work_item_id);
    $before = [$item->transitions()->count(), WorkOrchestrationEvent::query()->count()];
    expect($engine->answer($human, 'supervisor', 'Changed', CarbonImmutable::now())->answer)->toBe('Accepted');
    expect(fn () => $engine->answer($human, 'other', 'Changed', CarbonImmutable::now()))->toThrow(DomainException::class);
    expect([$item->transitions()->count(), WorkOrchestrationEvent::query()->count()])->toBe($before);
});

test('escalation options and recommendation reject invalid shapes and bounds before writes', function (array $options, string $recommendation): void {
    $parent = orchestrationTask();
    expect(fn () => resolve(WorkOrchestrationService::class)->escalate($parent, 'invalid', 'supervisor', 'Question', $options, $recommendation))->toThrow(DomainException::class);
    expect(WorkEscalation::query()->count())->toBe(0)->and($parent->items()->count())->toBe(0);
})->with([
    [[], 'Continue'], [['key' => 'Continue'], 'Continue'], [[123], 'Continue'], [[''], 'Continue'],
    [[str_repeat('x', 2001)], 'Continue'], [array_fill(0, 11, 'Continue'), 'Continue'],
    [['Continue'], ''], [['Continue'], str_repeat('x', 2001)],
]);

test('escalation logging includes identifiers only', function (): void {
    $parent = orchestrationTask();
    $human = resolve(WorkOrchestrationService::class)->escalate($parent, 'logging', 'supervisor', 'Private question', ['Private option'], 'Private recommendation');
    $human->forceFill(['answer' => 'Private answer'])->save();
    Log::shouldReceive('warning')->once()->with('Work escalation requires an answer.', [
        'task_id' => $parent->id, 'item_id' => $human->work_item_id, 'escalation_id' => $human->id,
    ]);
    (new LogWorkNotifier)->escalated($human);
});

test('events refuse invalid UUID authors without durable effects', function (): void {
    $parent = orchestrationTask();
    expect(fn () => resolve(WorkOrchestrationService::class)->event($parent, 'bad', 'test', authorTaskId: 'human'))->toThrow(DomainException::class);
    expect(WorkOrchestrationEvent::query()->count())->toBe(0)->and(WorkOrchestratorSeat::query()->count())->toBe(0)
        ->and($parent->items()->count())->toBe(0);
});

test('turn budget stops automatic work with one addressed unclaimable circuit breaker', function (): void {
    config(['capstan.work.orchestration.turn_budget' => 1]);
    $notifier = Mockery::mock(WorkNotifier::class);
    $notifier->shouldReceive('escalated')->once()->withArgs(fn (WorkEscalation $arg): bool => $arg->request_key === 'circuit-breaker' && $arg->notified_at !== null);
    app()->instance(WorkNotifier::class, $notifier);
    $parent = orchestrationTask();
    $host = orchestrationHost();
    orchestrationEvent($parent);
    $turn = orchestrationClaims($parent, $host)[0];
    orchestrationEvent($parent, 'next');
    resolve(WorkOrchestrationService::class)->turnDone($turn, 1, $turn->seat_fence, 'Done', CarbonImmutable::now());
    foreach (range(1, 5) as $index) {
        orchestrationEvent($parent, "overrun-$index");
    }
    $human = WorkEscalation::query()->sole();
    expect($human->metadata['reason'])->toBe('turn_budget')->and($human->addressed_actor)->toBe('supervisor')
        ->and($human->metadata['options'])->toBe(['Review the task before resuming', 'Cancel the task'])
        ->and($human->metadata['recommendation'])->toBe('Review the task before resuming')
        ->and(WorkItem::query()->findOrFail($human->work_item_id)->addressed_actor)->toBe('supervisor')
        ->and($parent->items()->where('role', 'human')->count())->toBe(1)
        ->and(orchestrationSeat($parent)->stopped_at)->not->toBeNull()
        ->and(orchestrationClaims($parent, $host, ['human', 'orchestrator']))->toBe([]);
});

test('child budget counts only orchestrator created children and cancels automatic work once', function (): void {
    config(['capstan.work.orchestration.child_budget' => 1]);
    $parent = orchestrationTask();
    $child = orchestrationTask($parent);
    foreach (range(1, 4) as $index) {
        $child->items()->create(['role' => 'builder']);
    }
    orchestrationEvent($parent);
    $host = orchestrationHost();
    $turn = orchestrationClaims($parent, $host)[0];
    $engine = resolve(WorkOrchestrationService::class);
    expect($engine->queueChild($turn, 1, $turn->seat_fence, $child, 'reviewer', CarbonImmutable::now()))->not->toBeNull();
    expect($engine->queueChild($turn, 1, $turn->seat_fence, $child, 'reviewer', CarbonImmutable::now()))->toBeNull();
    expect(fn () => $engine->queueChild($turn, 1, $turn->seat_fence, $child, 'reviewer', CarbonImmutable::now()))->toThrow(DomainException::class);
    orchestrationEvent($parent, 'repeated');
    expect(WorkEscalation::query()->sole()->metadata['reason'])->toBe('child_budget')
        ->and(orchestrationSeat($parent)->children_created)->toBe(1)
        ->and($child->items()->count())->toBe(5)->and($turn->fresh()->state)->toBe(WorkItemState::Cancelled)
        ->and(orchestrationClaims($parent, $host, ['human', 'orchestrator']))->toBe([]);
});

test('records use UUIDv7 and decisions and state revisions reject edits or deletion', function (): void {
    $parent = orchestrationTask();
    $event = orchestrationEvent($parent);
    $host = orchestrationHost();
    $turn = orchestrationClaims($parent, $host)[0];
    $engine = resolve(WorkOrchestrationService::class);
    $decision = $engine->decide($turn, 1, $turn->seat_fence, $event->id, Str::random(), Str::random(), ['absent' => null, 'present' => 'proof'], CarbonImmutable::now());
    $revision = $engine->turnDone($turn, 1, $turn->seat_fence, Str::random(), CarbonImmutable::now());
    $wake = $engine->requestWake($parent, 'uuid', CarbonImmutable::now(), 'Check');
    $human = $engine->escalate($parent, 'uuid', 'supervisor', 'Question', ['Continue'], 'Continue');
    foreach ([$event, $decision, $revision, $wake, $human, orchestrationSeat($parent)] as $record) {
        expect($record->id[14])->toBe('7');
    }
    expect($decision->evidence)->not->toHaveKey('absent');
    foreach ([$event, $decision, $revision] as $record) {
        expect(fn () => $record->delete())->toThrow(LogicException::class);
        expect(fn () => $record->forceFill(['work_task_id' => orchestrationTask()->id])->save())->toThrow(LogicException::class);
    }
});

test('work off engine services and scheduled execution have no durable effects', function (): void {
    $parent = orchestrationTask();
    $host = orchestrationHost();
    orchestrationEvent($parent);
    $turn = orchestrationClaims($parent, $host)[0];
    $engine = resolve(WorkOrchestrationService::class);
    $human = $engine->escalate($parent, 'off', 'supervisor', 'Question', ['Continue'], 'Continue');
    $engine->requestWake($parent, 'off', CarbonImmutable::now(), 'Due');
    Date::setTestNow(now()->addMinutes(6));
    $tables = ['work_items', 'work_orchestrator_seats', 'work_orchestration_events', 'work_orchestration_decisions', 'work_state_revisions', 'work_wakes', 'work_escalations'];
    $snapshot = fn () => array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
    $before = $snapshot();
    config(['capstan.features.work' => false]);
    Feature::flushCache();
    $engine->event($parent, 'off', 'off');
    $engine->requestWake($parent, 'off-new', CarbonImmutable::now(), 'Off');
    $engine->escalate($parent, 'off-new', 'supervisor', 'Off', ['Continue'], 'Continue');
    $engine->answer($human, 'supervisor', 'Off', CarbonImmutable::now());
    $engine->turnDone($turn, 1, $turn->seat_fence, 'Off', CarbonImmutable::now());
    resolve(WorkSeatService::class)->renew($parent, $host->actor_id, $host->id, $turn->seat_fence, 'Off', CarbonImmutable::now());
    resolve(WorkSeatService::class)->release($parent, $host->actor_id, $host->id, $turn->seat_fence, CarbonImmutable::now());
    $this->artisan('work:orchestration-sweep')->assertSuccessful();
    expect(orchestrationClaims($parent, $host))->toBe([])->and($snapshot())->toBe($before);
    $event = collect(resolve(Schedule::class)->events())->first(fn ($event): bool => str_contains($event->command ?? '', 'work:orchestration-sweep'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *')->and($event->withoutOverlapping)->toBeTrue();
});

test('terminal milestone and MCP cancellation writers project inside their source transaction', function (): void {
    $parent = orchestrationTask();
    $child = orchestrationTask($parent);
    $host = orchestrationHost();
    $done = $child->items()->create([
        'role' => 'builder', 'state' => 'routed', 'fence' => 1,
        'claimant_spoke_id' => $host->id, 'claimant_actor' => $host->actor_id,
        'lease_until' => now()->addMinutes(5),
    ]);
    DB::beginTransaction();
    resolve(WorkWorkerService::class)->milestone($done->id, 1, WorkMilestoneKind::Done, ['summary' => 'Completed']);
    expect(WorkOrchestrationEvent::query()->count())->toBe(1);
    DB::rollBack();
    expect($done->fresh()->state)->toBe(WorkItemState::Routed)
        ->and($done->milestones()->count())->toBe(0)->and(WorkOrchestrationEvent::query()->count())->toBe(0);
    resolve(WorkWorkerService::class)->milestone($done->id, 1, WorkMilestoneKind::Done, ['summary' => 'Completed']);
    $cancelled = $child->items()->create(['role' => 'builder']);
    resolve(WorkToolService::class)->write('cancel_item', ['team_id' => $parent->team_id, 'item_id' => $cancelled->id], 'supervisor');
    $taskCancelled = $child->items()->create(['role' => 'reviewer']);
    resolve(WorkToolService::class)->write('cancel_task', ['team_id' => $parent->team_id, 'task_id' => $child->id], 'supervisor');
    expect(WorkOrchestrationEvent::query()->count())->toBe(3)
        ->and($taskCancelled->fresh()->state)->toBe(WorkItemState::Cancelled)
        ->and($parent->items()->where('role', 'orchestrator')->count())->toBe(1);
});

test('direct seat transfer invalidates a held item and item renewal keeps the seat live', function (): void {
    $parent = orchestrationTask();
    $a = orchestrationHost();
    $b = orchestrationHost('host-b');
    orchestrationEvent($parent);
    $turn = orchestrationClaims($parent, $a)[0];
    Date::setTestNow(now()->addMinutes(4));
    $renewed = resolve(WorkItemStateService::class)->renewClaim($turn, $parent->team_id, $a->id, $a->actor_id, 1, CarbonImmutable::now(), CarbonImmutable::now()->addMinutes(5));
    Date::setTestNow(now()->addMinutes(2));
    resolve(WorkSeatService::class)->sweep(CarbonImmutable::now());
    expect($renewed)->not->toBeNull()->and(orchestrationSeat($parent)->holder_spoke_id)->toBe($a->id)
        ->and(orchestrationSeat($parent)->lease_until->equalTo($renewed->lease_until))->toBeTrue();
    Date::setTestNow(now()->addMinutes(4));
    $newSeat = resolve(WorkSeatService::class)->take($parent, $b->actor_id, $b->id, $turn->seat_fence, 'cold-session', false, CarbonImmutable::now());
    expect($newSeat->fence)->toBeGreaterThan($turn->seat_fence)->and($turn->fresh()->state)->toBe(WorkItemState::Queued)
        ->and($turn->fresh()->fence)->toBeGreaterThan($turn->fence);
    expect(fn () => resolve(WorkOrchestrationService::class)->turnDone($turn, 1, $turn->seat_fence, 'stale', CarbonImmutable::now()))->toThrow(DomainException::class);
    expect(orchestrationClaims($parent, $a))->toBe([]);
    $replacement = orchestrationClaims($parent, $b)[0];
    expect($replacement->session_ref)->toBe('cold-session')->and($replacement->turn_upper_bound)->toBe(1);
});

test('generic state claims cannot bypass orchestration arbitration or claim human work', function (): void {
    $parent = orchestrationTask();
    $host = orchestrationHost();
    foreach (['human', 'orchestrator'] as $role) {
        $item = $parent->items()->create(['role' => $role]);
        expect(fn () => resolve(WorkItemStateService::class)->claim($item, $host->id, $host->actor_id, CarbonImmutable::now()->addMinute(), 'spoke', $host->actor_id))->toThrow(DomainException::class);
        expect($item->fresh()->state)->toBe(WorkItemState::Queued)->and($item->transitions()->count())->toBe(0);
    }
});

test('failed turns spend the turn budget while lease recovery does not spend it twice', function (): void {
    config(['capstan.work.orchestration.turn_budget' => 1]);
    $parent = orchestrationTask();
    $host = orchestrationHost();
    orchestrationEvent($parent);
    $turn = orchestrationClaims($parent, $host)[0];
    Date::setTestNow(now()->addSeconds(301));
    resolve(WorkItemStateService::class)->expireLease($turn, 'system', 'expiry', CarbonImmutable::now());
    $turn = orchestrationClaims($parent, $host)[0];
    expect(orchestrationSeat($parent)->turns_started)->toBe(1);
    $states = resolve(WorkItemStateService::class);
    $turn = $states->transition($turn, WorkItemState::Delivered, 'spoke', $host->actor_id);
    $turn = $states->transition($turn, WorkItemState::Routed, 'spoke', $host->actor_id);
    $states->transition($turn, WorkItemState::Failed, 'spoke', $host->actor_id);
    expect(WorkEscalation::query()->sole()->metadata['reason'])->toBe('turn_budget')
        ->and(orchestrationClaims($parent, $host))->toBe([])
        ->and(orchestrationSeat($parent)->turns_completed)->toBe(0);
});

test('seat session renewal and explicit fencing preserve memory and reject the prior authority', function (): void {
    $parent = orchestrationTask();
    $host = orchestrationHost();
    orchestrationEvent($parent);
    $turn = orchestrationClaims($parent, $host)[0];
    $seats = resolve(WorkSeatService::class);
    $opaque = 'exact/'.Str::random().' ? preserve CASE';
    $renewed = $seats->renew($parent, $host->actor_id, $host->id, $turn->seat_fence, $opaque, CarbonImmutable::now());
    expect($renewed->session_ref)->toBe($opaque);
    $fenced = $seats->fence($parent, $host->actor_id, $host->id, $turn->seat_fence, CarbonImmutable::now());
    expect($fenced->fence)->toBeGreaterThan($turn->seat_fence)->and($fenced->session_ref)->toBe($opaque)
        ->and(WorkOrchestrationEvent::query()->count())->toBe(1)->and($fenced->completed_cursor)->toBe(0);
    expect(fn () => $seats->release($parent, $host->actor_id, $host->id, $turn->seat_fence, CarbonImmutable::now()))->toThrow(DomainException::class);
    $replacement = orchestrationClaims($parent, $host)[0];
    expect($replacement->seat_fence)->toBe($fenced->fence)->and($replacement->session_ref)->toBe($opaque)
        ->and(orchestrationSeat($parent)->turns_started)->toBe(1);
});

test('pinned non failover parents wait once per outage including a never held seat', function (bool $held): void {
    config(['capstan.work.failover_after_seconds' => 300]);
    $parent = orchestrationTask();
    $a = orchestrationHost();
    $b = orchestrationHost('host-b');
    $parent->update(['device_spoke_id' => $a->id, 'failover' => false]);
    $seats = resolve(WorkSeatService::class);
    $ref = "opaque://Exact ? CASE=Yes\n".Str::random();
    if ($held) {
        $seats->take($parent, $a->actor_id, $a->id, 0, $ref, false, CarbonImmutable::now(), 1);
    }
    orchestrationEvent($parent);
    $item = $parent->items()->sole();
    Log::shouldReceive('warning')->twice()->with('Work orchestrator waiting on pinned host.', [
        'task_id' => $parent->id, 'seat_id' => orchestrationSeat($parent)->id,
    ]);
    Date::setTestNow(now()->addSeconds(299));
    $seats->sweep(CarbonImmutable::now());
    expect(orchestrationSeat($parent)->waiting_at)->toBeNull()->and($item->fresh()->waiting_on_host_at)->toBeNull();
    Date::setTestNow(now()->addSecond());
    foreach (range(1, 2) as $outage) {
        $seats->sweep(CarbonImmutable::now());
        $seats->sweep(CarbonImmutable::now());
        expect(orchestrationSeat($parent)->waiting_at)->not->toBeNull()
            ->and(orchestrationSeat($parent)->notified_at)->not->toBeNull()
            ->and($item->fresh()->waiting_on_host_at)->not->toBeNull()
            ->and($item->fresh()->waiting_on_host_notified_at)->not->toBeNull()
            ->and(orchestrationClaims($parent, $b))->toBe([]);
        $turn = orchestrationClaims($parent, $a)[0];
        expect($turn->session_ref)->toBe($held || $outage === 2 ? $ref : null)
            ->and($turn->waiting_on_host_at)->toBeNull()->and($turn->waiting_on_host_notified_at)->toBeNull()
            ->and(orchestrationSeat($parent)->waiting_at)->toBeNull()->and(orchestrationSeat($parent)->notified_at)->toBeNull()
            ->and($parent->fresh()->device_spoke_id)->toBe($a->id);
        $seats->renew($parent, $a->actor_id, $a->id, $turn->seat_fence, $ref, CarbonImmutable::now(), 1);
        resolve(WorkOrchestrationService::class)->turnDone($turn, $turn->fence, $turn->seat_fence, 'Resumed', CarbonImmutable::now());
        if ($outage === 1) {
            orchestrationEvent($parent, 'second-outage');
            $item = $parent->items()->where('state', 'queued')->sole();
            Date::setTestNow(now()->addSeconds(300));
        }
    }
})->with(['previous holder' => true, 'never held' => false]);

test('failed conditional orchestration claim rolls back seat acquisition and takeover', function (bool $held): void {
    $parent = orchestrationTask();
    $a = orchestrationHost();
    $b = orchestrationHost('host-b');
    if ($held) {
        resolve(WorkSeatService::class)->take($parent, $a->actor_id, $a->id, 0, 'opaque/exact ? CASE', false, CarbonImmutable::now(), 1);
    }
    orchestrationEvent($parent);
    Date::setTestNow(now()->addSeconds(2));
    $claims = resolve(WorkClaimService::class);
    $candidates = new ReflectionMethod($claims, 'candidates');
    $candidate = $candidates->invoke($claims, $parent->team_id, $b->actor_id, $b->id, ['orchestrator'], [], 1, CarbonImmutable::now())[0];
    $parent->update(['title' => 'Changed after candidate selection']);
    $tables = ['work_orchestrator_seats', 'work_items', 'work_item_transitions'];
    $snapshot = fn () => array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
    $before = $snapshot();
    $claim = new ReflectionMethod($claims, 'claimCandidate');
    expect($claim->invoke($claims, $candidate, $parent->team_id, $b->actor_id, $b->id, CarbonImmutable::now(), 300))->toBeNull();
    expect($snapshot())->toBe($before);
    $seat = orchestrationSeat($parent);
    expect($seat->holder_actor)->toBe($held ? $a->actor_id : null)
        ->and($seat->holder_spoke_id)->toBe($held ? $a->id : null)
        ->and($seat->fence)->toBe($held ? 1 : 0)
        ->and($seat->session_ref)->toBe($held ? 'opaque/exact ? CASE' : null);
    expect(orchestrationClaims($parent, $b))->toHaveCount(1);
})->with(['acquisition' => false, 'takeover' => true]);
