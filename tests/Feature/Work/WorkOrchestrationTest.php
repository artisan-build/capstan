<?php

use App\Enums\WorkItemState;
use App\Enums\WorkMilestoneKind;
use App\Http\ApiErrorException;
use App\Livewire\Work\Index;
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
use App\Work\WorkOrchestrationCapability;
use App\Work\WorkOrchestrationContext;
use App\Work\WorkOrchestrationMcpService;
use App\Work\WorkOrchestrationService;
use App\Work\WorkSeatService;
use App\Work\WorkSweepService;
use App\Work\WorkToolService;
use App\Work\WorkWorkerLinks;
use App\Work\WorkWorkerService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

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

test('never held pinned failover waits for the timeout then transfers without changing pins or leaf placement', function (): void {
    config(['capstan.work.failover_after_seconds' => 300]);
    $parent = orchestrationTask();
    $a = orchestrationHost();
    $b = orchestrationHost('host-b');
    $parent->update(['device_spoke_id' => $a->id, 'failover' => true]);
    $child = orchestrationTask($parent);
    $child->update(['device_spoke_id' => $a->id, 'failover' => false]);
    $leaf = $child->items()->create(['role' => 'builder']);
    orchestrationEvent($parent);
    $item = $parent->items()->where('role', 'orchestrator')->sole();
    $notifier = Mockery::mock(WorkNotifier::class);
    $notifier->shouldNotReceive('seatWaiting');
    app()->instance(WorkNotifier::class, $notifier);

    expect(orchestrationClaims($parent, $b, ['orchestrator', 'builder']))->toBe([]);
    Date::setTestNow(now()->addSeconds(299));
    expect(orchestrationClaims($parent, $b, ['orchestrator', 'builder']))->toBe([]);
    Date::setTestNow(now()->addSecond());
    $replacement = orchestrationClaims($parent, $b, ['orchestrator', 'builder']);

    expect($replacement)->toHaveCount(1)
        ->and($replacement[0]->id)->toBe($item->id)
        ->and($replacement[0]->role)->toBe(WorkItem::ROLE_ORCHESTRATOR)
        ->and($parent->fresh()->device_spoke_id)->toBe($a->id)
        ->and($child->fresh()->device_spoke_id)->toBe($a->id)
        ->and($leaf->fresh()->state)->toBe(WorkItemState::Queued)
        ->and(orchestrationSeat($parent)->holder_spoke_id)->toBe($b->id)
        ->and(orchestrationSeat($parent)->waiting_at)->toBeNull()
        ->and(orchestrationSeat($parent)->notified_at)->toBeNull();
});

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

test('signed claimed-turn links expose fixed context and all scoped actions', function (): void {
    $addressed = capstanUser();
    $parent = orchestrationTask();
    $parent->briefRevisions()->create(['sequence' => 1, 'content' => 'Canonical brief', 'author_actor' => 'supervisor']);
    $child = orchestrationTask($parent);
    $event = orchestrationEvent($parent, 'signed-fixed');
    WorkOrchestrationDecision::query()->create([
        'work_task_id' => $parent->id, 'triggering_event_id' => $event->id,
        'decision' => 'Prior choice', 'rationale' => 'Prior rationale', 'evidence' => [],
    ]);
    $oldItem = $parent->items()->create(['role' => 'orchestrator', 'state' => 'acted']);
    WorkStateRevision::query()->create([
        'work_task_id' => $parent->id, 'work_item_id' => $oldItem->id,
        'sequence' => 1, 'through_cursor' => 0, 'content' => 'Latest state',
    ]);
    $host = orchestrationHost('signed-host');
    $turn = orchestrationClaims($parent, $host)[0];
    orchestrationSeat($parent)->forceFill(['turns_completed' => 1])->save();
    orchestrationEvent($parent, 'signed-later');
    $links = resolve(WorkWorkerLinks::class)->forItem($turn);
    expect($links)->toHaveKeys(['orchestration', 'orchestrate']);
    $this->getJson($links['orchestration'])->assertOk()
        ->assertJsonPath('context.task.id', $parent->id)
        ->assertJsonPath('context.brief.content', 'Canonical brief')
        ->assertJsonPath('context.state.content', 'Latest state')
        ->assertJsonPath('context.decisions.0.decision', 'Prior choice')
        ->assertJsonCount(1, 'context.events')
        ->assertJsonPath('context.events.0.id', $event->id);
    $post = (fn (string $action, array $input, string $key): array => $this->postJson($links['orchestrate'], ['action' => $action, 'input' => $input, 'idempotency_key' => $key])
        ->assertOk()->assertJsonPath('outcome', 'accepted')->json());
    $created = $post('create_child', ['under_task_id' => $parent->id, 'title' => 'Signed child', 'brief' => 'Signed brief'], 'signed-create');
    $post('revise_brief', ['task_id' => $child->id, 'brief' => 'Signed revision'], 'signed-revise');
    $queued = $post('queue_item', ['task_id' => $child->id, 'role' => 'builder'], 'signed-queue');
    $replay = $this->postJson($links['orchestrate'], ['action' => 'queue_item', 'input' => ['task_id' => $child->id, 'role' => 'builder'], 'idempotency_key' => 'signed-queue'])
        ->assertOk()->assertJsonPath('outcome', 'already_accepted')->json();
    $post('steer', ['task_id' => $child->id, 'item_id' => $queued['item_id'], 'content' => 'Signed steer'], 'signed-steer');
    $post('cancel_item', ['item_id' => $queued['item_id']], 'signed-cancel');
    $post('decide', ['event_id' => $event->id, 'decision' => 'Signed decision', 'rationale' => 'Signed rationale', 'evidence' => ['source' => 'test']], 'signed-decide');
    $post('request_wake', ['key' => 'signed-wake', 'due_at' => now()->addMinute()->toJSON(), 'reason' => 'Signed follow-up'], 'signed-wake');
    $post('escalate', ['key' => 'signed-question', 'addressed_actor' => (string) $addressed->id, 'question' => 'Signed question?', 'options' => ['Yes'], 'recommendation' => 'Yes'], 'signed-escalate');
    $finished = $post('finish', ['state' => 'acted', 'content' => 'Signed state'], 'signed-finish');

    expect($created['task_id'])->not->toBe($parent->id)
        ->and($replay['item_id'])->toBe($queued['item_id'])
        ->and($finished['status'])->toBe('completed')
        ->and(orchestrationSeat($parent)->children_created)->toBe(1)
        ->and(WorkStateRevision::query()->where('work_task_id', $parent->id)->count())->toBe(2);
});

test('signed child creation enforces canonical intent and team failover defaults before durable effects', function (): void {
    $team = Team::default();
    $parent = orchestrationTask();
    $event = orchestrationEvent($parent, 'signed-child-contract');
    $turn = orchestrationClaims($parent, orchestrationHost('signed-child-contract-host'))[0];
    $link = resolve(WorkWorkerLinks::class)->forItem($turn)['orchestrate'];
    $active = capstanUser();
    $inactive = capstanUser(['status' => 'inactive']);
    $spoke = Spoke::query()->create(['actor_id' => (string) $active->id, 'credential_id' => (string) Str::uuid7()]);
    $orphan = Spoke::query()->create(['actor_id' => '999999999', 'credential_id' => (string) Str::uuid7()]);
    $post = fn (array $input, string $key) => $this->postJson($link, [
        'action' => 'create_child', 'input' => array_replace(['under_task_id' => $parent->id, 'title' => 'Child', 'brief' => 'Brief'], $input),
        'idempotency_key' => $key,
    ]);
    $refuse = function (array $input, string $key) use ($post): void {
        $before = [WorkTask::query()->count(), DB::table('work_brief_revisions')->count(), DB::table('mcp_write_claims')->count()];
        $post($input, $key)->assertUnprocessable()->assertJsonPath('error.code', 'invalid_orchestration_action');
        expect([WorkTask::query()->count(), DB::table('work_brief_revisions')->count(), DB::table('mcp_write_claims')->count()])->toBe($before);
    };

    $refuse(['device_spoke_id' => $spoke->id], 'signed-pin-no-failover');
    $refuse(['device_spoke_id' => 999999999, 'failover' => true], 'signed-missing-spoke');
    $refuse(['device_spoke_id' => $orphan->id, 'failover' => true], 'signed-invalid-spoke');
    $refuse(['user_actor_address' => 'missing'], 'signed-invalid-actor');
    $refuse(['user_actor_address' => '999999999'], 'signed-missing-actor');
    $refuse(['user_actor_address' => (string) $inactive->id], 'signed-inactive-actor');
    $refuse(['brief' => ''], 'signed-empty-brief');
    $refuse(['brief' => str_repeat('b', 32001)], 'signed-brief-overrun');
    $refuse(['timebox_minutes' => 0], 'signed-timebox-underrun');
    $refuse(['timebox_minutes' => 1441], 'signed-timebox-overrun');
    $refuse(['required_capabilities' => ['duplicate', 'duplicate']], 'signed-capabilities-duplicate');
    $refuse(['required_capabilities' => [str_repeat('c', 65)]], 'signed-capability-length');
    $refuse(['required_capabilities' => array_map(fn (int $i): string => 'cap-'.$i, range(1, 26))], 'signed-capabilities-overrun');

    foreach ([false, true] as $default) {
        $team->update(['default_failover' => $default]);
        $capabilities = array_map(fn (int $i): string => 'cap-'.$i, range(1, 25));
        $response = $post([
            'brief' => str_repeat('b', 32000), 'user_actor_address' => (string) $active->id,
            'timebox_minutes' => 1440, 'required_capabilities' => $capabilities,
        ], 'signed-default-'.(int) $default)->assertOk()->assertJsonPath('outcome', 'accepted')->json();
        $child = WorkTask::query()->findOrFail($response['task_id']);
        expect($child->failover)->toBe($default)
            ->and($child->timebox_minutes)->toBe(1440)
            ->and($child->required_capabilities)->toBe($capabilities)
            ->and($child->latestBriefRevision()->firstOrFail()->content)->toHaveLength(32000);
    }
    expect($event->exists)->toBeTrue();
});

test('signed fixed turn and decision context paginates completely within live authority', function (): void {
    $parent = orchestrationTask();
    orchestrationEvent($parent, 'completed');
    $host = orchestrationHost('signed-page-host');
    $completed = orchestrationClaims($parent, $host)[0];
    resolve(WorkOrchestrationService::class)->turnDone($completed, $completed->fence, $completed->seat_fence, 'Completed', CarbonImmutable::now());
    $events = collect(range(1, 27))->map(fn (int $index): WorkOrchestrationEvent => orchestrationEvent($parent, 'page-'.$index));
    $turn = orchestrationClaims($parent, $host)[0];
    $later = orchestrationEvent($parent, 'outside-fixed-turn');
    foreach (range(1, 27) as $index) {
        WorkOrchestrationDecision::query()->create([
            'work_task_id' => $parent->id, 'triggering_event_id' => $events[0]->id,
            'decision' => 'Decision '.$index, 'rationale' => 'Rationale '.$index, 'evidence' => ['index' => $index],
        ]);
    }
    $expectedDecisions = WorkOrchestrationDecision::query()->where('work_task_id', $parent->id)->orderBy('id')->pluck('id')->all();
    $link = resolve(WorkWorkerLinks::class)->forItem($turn)['orchestration'];
    $first = $this->getJson($link)->assertOk()
        ->assertJsonCount(25, 'context.events')->assertJsonPath('context.has_more', true)
        ->assertJsonPath('context.next_cursor', 26)->assertJsonCount(25, 'context.decisions')
        ->assertJsonPath('context.decisions_has_more', true)->json('context');
    $second = $this->getJson($link.'&after='.$first['next_cursor'].'&decisions_after='.$first['decisions_next_cursor'])
        ->assertOk()->assertJsonCount(2, 'context.events')->assertJsonPath('context.has_more', false)
        ->assertJsonCount(2, 'context.decisions')->assertJsonPath('context.decisions_has_more', false)->json('context');

    expect(array_column([...$first['events'], ...$second['events']], 'id'))->toBe($events->pluck('id')->all())
        ->and(array_column([...$first['decisions'], ...$second['decisions']], 'id'))->toBe($expectedDecisions)
        ->and(array_column([...$first['events'], ...$second['events']], 'id'))->not->toContain($later->id);
    $this->getJson($link.'&after=bogus')->assertUnprocessable();
    $this->getJson($link.'&after=0')->assertUnprocessable()->assertJsonPath('error.code', 'invalid_cursor');
    $this->getJson($link.'&after='.($turn->turn_upper_bound + 1))->assertUnprocessable()->assertJsonPath('error.code', 'invalid_cursor');

    $turn->increment('fence');
    $this->getJson($link.'&after='.$first['next_cursor'])->assertForbidden();

    $expiringParent = orchestrationTask();
    foreach (range(1, 27) as $index) {
        orchestrationEvent($expiringParent, 'expiring-'.$index);
    }
    $expiring = orchestrationClaims($expiringParent, orchestrationHost('signed-expiring-page'))[0];
    $expiringLink = resolve(WorkWorkerLinks::class)->forItem($expiring)['orchestration'];
    $cursor = $this->getJson($expiringLink)->assertOk()->json('context.next_cursor');
    Date::setTestNow(now()->addSeconds(361));
    $this->getJson($expiringLink.'&after='.$cursor)->assertForbidden();
});

test('signed decision evidence bounds and surfaced action limits refuse before durable effects', function (): void {
    $addressed = capstanUser();
    $inactive = capstanUser(['status' => 'inactive']);
    $parent = orchestrationTask();
    $child = orchestrationTask($parent);
    $event = orchestrationEvent($parent, 'signed-input-bounds');
    $turn = orchestrationClaims($parent, orchestrationHost('signed-input-host'))[0];
    $link = resolve(WorkWorkerLinks::class)->forItem($turn)['orchestrate'];
    $post = fn (string $action, array $input, string $key) => $this->postJson($link, compact('action', 'input') + ['idempotency_key' => $key]);
    $validEvidence = ['source' => ['kind' => 'test', 'indexes' => [1, 2, 3]]];
    $post('decide', ['event_id' => $event->id, 'decision' => 'Proceed', 'rationale' => 'Evidence is bounded', 'evidence' => $validEvidence], 'signed-valid-evidence')
        ->assertOk()->assertJsonPath('outcome', 'accepted');
    expect(WorkOrchestrationDecision::query()->latest('created_at')->firstOrFail()->evidence)->toBe($validEvidence);

    $deep = ['value' => true];
    foreach (range(1, 7) as $level) {
        $deep = ['level' => $deep];
    }
    $invalidEvidence = [
        'string' => ['value' => str_repeat('x', 8001)],
        'depth' => $deep,
        'list-root' => ['first', 'second'],
        'encoded-bytes' => ['first' => str_repeat('a', 4100), 'second' => str_repeat('b', 4100)],
    ];
    foreach ($invalidEvidence as $key => $evidence) {
        $before = [WorkOrchestrationDecision::query()->count(), DB::table('mcp_write_claims')->count()];
        $post('decide', ['event_id' => $event->id, 'decision' => 'No', 'rationale' => 'Invalid evidence', 'evidence' => $evidence], 'signed-evidence-'.$key)
            ->assertUnprocessable()->assertJsonPath('error.code', 'invalid_orchestration_action');
        expect([WorkOrchestrationDecision::query()->count(), DB::table('mcp_write_claims')->count()])->toBe($before);
    }

    $post('revise_brief', ['task_id' => $child->id, 'brief' => str_repeat('r', 32000)], 'signed-revise-boundary')->assertOk();
    $post('steer', ['task_id' => $child->id, 'content' => str_repeat('s', 8000)], 'signed-steer-boundary')->assertOk();
    foreach ([
        ['revise_brief', ['task_id' => $child->id, 'brief' => str_repeat('r', 32001)], 'signed-revise-over'],
        ['steer', ['task_id' => $child->id, 'content' => str_repeat('s', 8001)], 'signed-steer-over'],
        ['escalate', ['key' => 'missing', 'addressed_actor' => '999999999', 'question' => 'Missing?', 'options' => ['No'], 'recommendation' => 'No'], 'signed-escalate-missing'],
        ['escalate', ['key' => 'inactive', 'addressed_actor' => (string) $inactive->id, 'question' => 'Inactive?', 'options' => ['No'], 'recommendation' => 'No'], 'signed-escalate-inactive'],
    ] as [$action, $input, $key]) {
        $before = [DB::table('work_brief_revisions')->count(), DB::table('work_steers')->count(), WorkEscalation::query()->count(), WorkItem::query()->count(), DB::table('mcp_write_claims')->count()];
        $post($action, $input, $key)->assertUnprocessable()->assertJsonPath('error.code', 'invalid_orchestration_action');
        expect([DB::table('work_brief_revisions')->count(), DB::table('work_steers')->count(), WorkEscalation::query()->count(), WorkItem::query()->count(), DB::table('mcp_write_claims')->count()])->toBe($before);
    }
    $post('escalate', ['key' => 'valid', 'addressed_actor' => (string) $addressed->id, 'question' => 'Valid?', 'options' => ['Yes'], 'recommendation' => 'Yes'], 'signed-escalate-valid')
        ->assertOk()->assertJsonPath('outcome', 'accepted');
    expect(WorkEscalation::query()->where('request_key', 'valid')->sole()->addressed_actor)->toBe((string) $addressed->id);
});

test('signed queue replay preserves the final child slot and overrun raises one circuit breaker', function (): void {
    config(['capstan.work.orchestration.child_budget' => 1]);
    $parent = orchestrationTask();
    $child = orchestrationTask($parent);
    orchestrationEvent($parent, 'signed-budget');
    $turn = orchestrationClaims($parent, orchestrationHost('signed-budget-host'))[0];
    $link = resolve(WorkWorkerLinks::class)->forItem($turn)['orchestrate'];
    $body = fn (string $key, string $role): array => [
        'action' => 'queue_item', 'input' => ['task_id' => $child->id, 'role' => $role], 'idempotency_key' => $key,
    ];

    $first = $this->postJson($link, $body('final-slot', 'builder'))->assertOk()->assertJsonPath('outcome', 'accepted')->json();
    $this->postJson($link, $body('final-slot', 'builder'))->assertOk()
        ->assertJsonPath('outcome', 'already_accepted')->assertJsonPath('item_id', $first['item_id']);
    $this->postJson($link, $body('overrun', 'reviewer'))->assertOk()
        ->assertJsonPath('outcome', 'accepted')->assertJsonPath('status', 'budget_exhausted');
    $this->postJson($link, $body('overrun', 'reviewer'))->assertForbidden();

    expect(orchestrationSeat($parent)->children_created)->toBe(1)
        ->and($child->items()->where('role', 'builder')->count())->toBe(1)
        ->and($child->items()->where('role', 'reviewer')->count())->toBe(0)
        ->and(WorkEscalation::query()->where('request_key', 'circuit-breaker')->count())->toBe(1);
});

test('signed acted finish refuses blank state before effects and permits a corrected retry', function (array $invalidInput): void {
    $parent = orchestrationTask();
    orchestrationEvent($parent, 'signed-finish-state');
    $turn = orchestrationClaims($parent, orchestrationHost('signed-finish-state-host'))[0];
    $link = resolve(WorkWorkerLinks::class)->forItem($turn)['orchestrate'];
    $key = 'signed-finish-state';
    $snapshot = fn (): array => [
        WorkStateRevision::query()->where('work_task_id', $parent->id)->count(),
        orchestrationSeat($parent)->completed_cursor,
        $turn->fresh()->state,
        $turn->transitions()->count(),
        DB::table('mcp_write_claims')->count(),
    ];
    $before = $snapshot();

    $this->postJson($link, ['action' => 'finish', 'input' => $invalidInput, 'idempotency_key' => $key])
        ->assertUnprocessable()->assertJsonPath('error.code', 'invalid_orchestration_action');
    expect($snapshot())->toBe($before);

    $this->postJson($link, [
        'action' => 'finish',
        'input' => ['state' => 'acted', 'content' => 'Corrected signed state'],
        'idempotency_key' => $key,
    ])->assertOk()->assertJsonPath('outcome', 'accepted')->assertJsonPath('status', 'completed');

    $revision = WorkStateRevision::query()->where('work_task_id', $parent->id)->sole();
    expect($turn->fresh()->state)->toBe(WorkItemState::Acted)
        ->and($revision->content)->toBe('Corrected signed state')
        ->and($revision->through_cursor)->toBe(1)
        ->and(orchestrationSeat($parent)->completed_cursor)->toBe(1)
        ->and(DB::table('mcp_write_claims')->count())->toBe($before[4] + 1);
})->with([
    'omitted content' => [['state' => 'acted']],
    'empty content' => [['state' => 'acted', 'content' => '']],
]);

test('signed claimed-turn failure completes only a routed live turn once', function (): void {
    $parent = orchestrationTask();
    orchestrationEvent($parent, 'signed-failed');
    $turn = orchestrationClaims($parent, orchestrationHost('signed-failed-host'))[0];
    resolve(WorkItemStateService::class)->transition($turn, WorkItemState::Delivered, 'spoke', 'signed-failed-host');
    resolve(WorkItemStateService::class)->transition($turn->refresh(), WorkItemState::Routed, 'spoke', 'signed-failed-host');
    $link = resolve(WorkWorkerLinks::class)->forItem($turn->refresh())['orchestrate'];
    $body = ['action' => 'finish', 'input' => ['state' => 'failed'], 'idempotency_key' => 'signed-failed'];

    $this->postJson($link, $body)->assertOk()->assertJsonPath('outcome', 'accepted')->assertJsonPath('status', 'completed');
    $this->postJson($link, $body)->assertForbidden();
    expect($turn->fresh()->state)->toBe(WorkItemState::Failed)
        ->and($turn->transitions()->where('to_state', WorkItemState::Failed)->count())->toBe(1)
        ->and(WorkStateRevision::query()->where('work_task_id', $parent->id)->count())->toBe(0)
        ->and(orchestrationSeat($parent)->completed_cursor)->toBe(0);
});

test('ordinary worker links retain only ordinary authority', function (): void {
    $task = orchestrationTask();
    $host = orchestrationHost('ordinary-host');
    $item = $task->items()->create([
        'role' => 'builder', 'state' => 'claimed', 'fence' => 1, 'claimant_actor' => $host->actor_id,
        'claimant_spoke_id' => $host->id, 'claimed_at' => now(), 'lease_until' => now()->addMinutes(5),
    ]);

    expect(array_keys(resolve(WorkWorkerLinks::class)->forItem($item)))->toBe(['brief', 'milestone', 'steers']);
});

test('signed claimed authority refuses every out-of-subtree target and malformed payload without Work effects', function (): void {
    $root = orchestrationTask();
    $parent = orchestrationTask($root);
    $child = orchestrationTask($parent);
    $sibling = orchestrationTask($root);
    $siblingChild = orchestrationTask($sibling);
    $unrelated = orchestrationTask();
    $crossTeam = WorkTask::query()->create([
        'team_id' => Team::query()->create(['name' => 'Cross team', 'slug' => Str::random(16)])->id,
        'title' => 'Cross team task', 'required_capabilities' => [], 'timebox_minutes' => 10, 'creator_actor' => 'supervisor',
    ]);
    foreach ([$root, $parent, $siblingChild, $unrelated, $crossTeam] as $task) {
        $task->items()->create(['role' => 'builder']);
    }
    orchestrationEvent($parent, 'signed-scope');
    $turn = orchestrationClaims($parent, orchestrationHost('signed-scope-host'))[0];
    $link = resolve(WorkWorkerLinks::class)->forItem($turn)['orchestrate'];
    $snapshot = fn (): array => [
        WorkTask::query()->count(), WorkItem::query()->count(),
        DB::table('work_brief_revisions')->count(), DB::table('work_steers')->count(),
    ];
    $attempt = function (string $action, array $input, string $key) use ($link, $snapshot): void {
        $before = $snapshot();
        $this->postJson($link, ['action' => $action, 'input' => $input, 'idempotency_key' => $key])
            ->assertForbidden()->assertJsonPath('error.code', 'orchestration_forbidden');
        expect($snapshot())->toBe($before);
    };
    foreach ([$root, $siblingChild, $unrelated, $crossTeam] as $index => $outside) {
        $attempt('create_child', ['under_task_id' => $outside->id, 'title' => 'Forbidden', 'brief' => 'Forbidden'], 'scope-create-'.$index);
    }
    foreach ([$root, $parent, $siblingChild, $unrelated, $crossTeam] as $index => $outside) {
        $attempt('queue_item', ['task_id' => $outside->id, 'role' => 'builder'], 'scope-queue-'.$index);
        $attempt('steer', ['task_id' => $outside->id, 'content' => 'Forbidden'], 'scope-steer-'.$index);
        $attempt('cancel_item', ['item_id' => $outside->items()->firstOrFail()->id], 'scope-cancel-'.$index);
    }
    $before = $snapshot();
    $this->postJson($link, ['action' => 'queue_item', 'input' => ['task_id' => $child->id, 'role' => 'human'], 'idempotency_key' => 'scope-reserved'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'invalid_orchestration_action');
    $this->postJson($link, ['action' => 'queue_item', 'input' => ['task_id' => $child->id, 'role' => 'builder', 'unknown' => true], 'idempotency_key' => 'scope-malformed'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'invalid_orchestration_action');
    expect($snapshot())->toBe($before);
});

test('signed orchestration links reject stale item and seat fences and cancelled parents before effects', function (string $loss): void {
    $parent = orchestrationTask();
    $child = orchestrationTask($parent);
    orchestrationEvent($parent, 'signed-lost-'.$loss);
    $turn = orchestrationClaims($parent, orchestrationHost('signed-lost-host-'.$loss))[0];
    $links = resolve(WorkWorkerLinks::class)->forItem($turn);
    match ($loss) {
        'item_fence' => $turn->increment('fence'),
        'seat_fence' => orchestrationSeat($parent)->increment('fence'),
        'cancelled_parent' => $parent->update(['cancelled_at' => now()]),
        'expired_lease' => Date::setTestNow(now()->addSeconds(301)),
        'expired_link' => Date::setTestNow(now()->addSeconds(361)),
    };
    $before = [$child->items()->count(), DB::table('mcp_write_claims')->count()];

    $this->getJson($links['orchestration'])->assertForbidden();
    $this->postJson($links['orchestrate'], [
        'action' => 'queue_item', 'input' => ['task_id' => $child->id, 'role' => 'builder'], 'idempotency_key' => 'lost-'.$loss,
    ])->assertForbidden();
    expect([$child->items()->count(), DB::table('mcp_write_claims')->count()])->toBe($before);
})->with(['item_fence', 'seat_fence', 'cancelled_parent', 'expired_lease', 'expired_link']);

test('Work off hides signed orchestration entry points before effects', function (): void {
    $parent = orchestrationTask();
    $child = orchestrationTask($parent);
    orchestrationEvent($parent, 'signed-off');
    $turn = orchestrationClaims($parent, orchestrationHost('signed-off-host'))[0];
    $links = resolve(WorkWorkerLinks::class)->forItem($turn);
    config(['capstan.features.work' => false]);
    Feature::flushCache();
    $before = [$child->items()->count(), DB::table('mcp_write_claims')->count()];

    $this->getJson($links['orchestration'])->assertNotFound();
    $this->postJson($links['orchestrate'], [
        'action' => 'queue_item', 'input' => ['task_id' => $child->id, 'role' => 'builder'], 'idempotency_key' => 'signed-off',
    ])->assertNotFound();
    expect([$child->items()->count(), DB::table('mcp_write_claims')->count()])->toBe($before);
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
    expect(orchestrationClaims($parent, $b))->toBe([]);
    Date::setTestNow(now()->addSeconds(299));
    $seats->sweep(CarbonImmutable::now());
    expect(orchestrationSeat($parent)->waiting_at)->toBeNull()
        ->and($item->fresh()->waiting_on_host_at)->toBeNull()
        ->and(orchestrationClaims($parent, $b))->toBe([]);
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

test('a current orchestrator turn exercises the complete bounded subtree capability', function (): void {
    $parent = orchestrationTask();
    $existing = orchestrationTask($parent);
    $event = orchestrationEvent($parent, 'capability-current');
    $host = orchestrationHost();
    $turn = orchestrationClaims($parent, $host)[0];
    $context = new WorkOrchestrationContext(
        $parent->id, $host->actor_id, $host->id, $turn->seat_fence, null, $turn->id, $turn->fence,
    );
    $capability = resolve(WorkOrchestrationCapability::class);

    $created = $capability->createChild($context, $parent->id, [
        'title' => 'Capability child', 'required_capabilities' => [], 'timebox_minutes' => 5,
    ], 'Capability brief', CarbonImmutable::now());
    $revision = $capability->reviseBrief($context, $existing->id, 'Revised by orchestrator', CarbonImmutable::now());
    $item = $capability->queue($context, $existing->id, 'builder', CarbonImmutable::now());
    $steer = $capability->steer($context, $existing->id, $item->id, 'Proceed safely', CarbonImmutable::now());
    $cancelled = $capability->cancelItem($context, $item->id, CarbonImmutable::now());
    $decision = $capability->decide($context, $event->id, 'Proceed', 'Evidence checked', ['proof' => true], CarbonImmutable::now());
    $wake = $capability->requestWake($context, 'later', CarbonImmutable::now()->addMinute(), 'Recheck', CarbonImmutable::now());
    $escalation = $capability->escalate($context, 'question', 'supervisor', 'Continue?', ['Yes', 'No'], 'Yes', CarbonImmutable::now());
    $finished = $capability->finish($context, WorkItemState::Acted, 'Current turn complete', CarbonImmutable::now());

    expect($created->parent_task_id)->toBe($parent->id)
        ->and($created->briefRevisions()->sole()->content)->toBe('Capability brief')
        ->and($revision->work_task_id)->toBe($existing->id)
        ->and($steer->work_item_id)->toBe($item->id)
        ->and($cancelled->state)->toBe(WorkItemState::Cancelled)
        ->and($decision->work_task_id)->toBe($parent->id)
        ->and($wake->work_task_id)->toBe($parent->id)
        ->and($escalation->work_task_id)->toBe($parent->id)
        ->and($finished->state)->toBe(WorkItemState::Acted);
});

test('an interactive holder exercises subtree capability and loses it on expiry release replacement or spoofing', function (): void {
    $parent = orchestrationTask();
    $child = orchestrationTask($parent);
    orchestrationEvent($parent, 'capability-interactive');
    $a = orchestrationHost('interactive-a');
    $b = orchestrationHost('interactive-b');
    $seat = resolve(WorkSeatService::class)->take($parent, $a->actor_id, $a->id, 0, 'opaque-session', true, CarbonImmutable::now(), 10);
    $context = new WorkOrchestrationContext($parent->id, $a->actor_id, $a->id, $seat->fence, 'opaque-session');
    $capability = resolve(WorkOrchestrationCapability::class);
    $created = $capability->createChild($context, $parent->id, [
        'title' => 'Interactive child', 'required_capabilities' => [], 'timebox_minutes' => 5,
    ], 'Interactive brief', CarbonImmutable::now());
    $revision = $capability->reviseBrief($context, $child->id, 'Interactive revision', CarbonImmutable::now());
    $item = $capability->queue($context, $child->id, 'builder', CarbonImmutable::now());
    $steer = $capability->steer($context, $child->id, $item->id, 'Interactive steer', CarbonImmutable::now());
    $cancelled = $capability->cancelItem($context, $item->id, CarbonImmutable::now());
    $decision = $capability->decide($context, WorkOrchestrationEvent::query()->where('source_key', 'capability-interactive')->sole()->id, 'Continue', 'Interactive proof', [], CarbonImmutable::now());
    $wake = $capability->requestWake($context, 'interactive-wake', CarbonImmutable::now()->addMinute(), 'Check', CarbonImmutable::now());
    $escalation = $capability->escalate($context, 'interactive-question', 'supervisor', 'Continue?', ['Yes'], 'Yes', CarbonImmutable::now());
    $finished = $capability->finish($context, WorkItemState::Acted, 'Interactive complete', CarbonImmutable::now());
    expect($created->parent_task_id)->toBe($parent->id)
        ->and($revision->work_task_id)->toBe($child->id)
        ->and($steer->work_item_id)->toBe($item->id)
        ->and($cancelled->state)->toBe(WorkItemState::Cancelled)
        ->and($decision->work_task_id)->toBe($parent->id)
        ->and($wake->work_task_id)->toBe($parent->id)
        ->and($escalation->work_task_id)->toBe($parent->id)
        ->and($finished->state)->toBe(WorkItemState::Acted);

    foreach ([
        new WorkOrchestrationContext($parent->id, 'spoofed', $a->id, $seat->fence, 'opaque-session'),
        new WorkOrchestrationContext($parent->id, $a->actor_id, $b->id, $seat->fence, 'opaque-session'),
        new WorkOrchestrationContext($parent->id, $a->actor_id, $a->id, $seat->fence + 1, 'opaque-session'),
        new WorkOrchestrationContext($parent->id, $a->actor_id, $a->id, $seat->fence, 'wrong-session'),
    ] as $spoofed) {
        expect(fn () => $capability->queue($spoofed, $child->id, 'reviewer', CarbonImmutable::now()))
            ->toThrow(ApiErrorException::class);
    }
    expect($child->items()->count())->toBe(1);

    Date::setTestNow(now()->addSeconds(11));
    expect(fn () => $capability->queue($context, $child->id, 'expired', CarbonImmutable::now()))
        ->toThrow(ApiErrorException::class);
    $replacement = resolve(WorkSeatService::class)->take($parent, $b->actor_id, $b->id, $seat->fence, 'replacement', true, CarbonImmutable::now());
    expect(fn () => $capability->queue($context, $child->id, 'replaced', CarbonImmutable::now()))
        ->toThrow(ApiErrorException::class);
    $replacementContext = new WorkOrchestrationContext($parent->id, $b->actor_id, $b->id, $replacement->fence, 'replacement');
    resolve(WorkSeatService::class)->release($parent, $b->actor_id, $b->id, $replacement->fence, CarbonImmutable::now());
    expect(fn () => $capability->queue($replacementContext, $child->id, 'released', CarbonImmutable::now()))
        ->toThrow(ApiErrorException::class);
    expect($child->items()->count())->toBe(1);
});

test('subtree capability refuses ancestors siblings unrelated roots cross team reserved roles and stale turn fences without writes', function (): void {
    $root = orchestrationTask();
    $parent = orchestrationTask($root);
    $child = orchestrationTask($parent);
    $sibling = orchestrationTask($root);
    $siblingChild = orchestrationTask($sibling);
    $other = WorkTask::query()->create([
        'team_id' => Team::query()->create(['name' => 'Other capability team', 'slug' => Str::random(16)])->id,
        'title' => 'Other root', 'required_capabilities' => [], 'timebox_minutes' => 10, 'creator_actor' => 'supervisor',
    ]);
    orchestrationEvent($parent, 'capability-scope');
    $host = orchestrationHost('scope-host');
    $turn = orchestrationClaims($parent, $host)[0];
    $context = new WorkOrchestrationContext($parent->id, $host->actor_id, $host->id, $turn->seat_fence, null, $turn->id, $turn->fence);
    $capability = resolve(WorkOrchestrationCapability::class);
    $before = WorkItem::query()->count();

    foreach ([$root, $parent, $sibling, $siblingChild, $other] as $outside) {
        try {
            $capability->queue($context, $outside->id, 'builder', CarbonImmutable::now());
            $this->fail('Expected subtree refusal.');
        } catch (ApiErrorException $exception) {
            expect($exception->status)->toBe(403);
        }
    }
    expect(fn () => $capability->queue($context, $child->id, WorkItem::ROLE_HUMAN, CarbonImmutable::now()))
        ->toThrow(ApiErrorException::class);
    $stale = new WorkOrchestrationContext($parent->id, $host->actor_id, $host->id, $turn->seat_fence, null, $turn->id, $turn->fence + 1);
    expect(fn () => $capability->queue($stale, $child->id, 'builder', CarbonImmutable::now()))
        ->toThrow(ApiErrorException::class);
    expect(fn () => $capability->queue($context, (string) Str::uuid7(), 'builder', CarbonImmutable::now()))
        ->toThrow(ApiErrorException::class);
    $parent->forceFill(['cancelled_at' => now()])->save();
    expect(fn () => $capability->queue($context, $child->id, 'builder', CarbonImmutable::now()))
        ->toThrow(ApiErrorException::class);
    expect(WorkItem::query()->count())->toBe($before);
});

test('an answered escalation reaches the woken turn with its answer and never another task or another team', function (): void {
    $engine = resolve(WorkOrchestrationService::class);
    $addressed = capstanUser();
    $actor = (string) $addressed->id;
    $parent = orchestrationTask();
    $escalation = $engine->escalate($parent, 'answer-reaches-worker', $actor, 'Which way should the worker finish?', ['Option A: finish now', 'Option B: keep going'], 'Option A: finish now');

    $answer = 'Option A: finish now, and open a follow-up task tomorrow.';
    resolve(WorkOrchestrationMcpService::class)->answer($parent->team_id, $escalation->id, $actor, $answer, CarbonImmutable::now());
    // AC4: a replayed answer must not produce a second entry in the read.
    resolve(WorkOrchestrationMcpService::class)->answer($parent->team_id, $escalation->id, $actor, 'A different answer', CarbonImmutable::now());

    $host = orchestrationHost('answer-reaches-host');
    $turn = orchestrationClaims($parent, $host)[0];
    $read = $this->getJson(resolve(WorkWorkerLinks::class)->forItem($turn)['orchestration'])->assertOk();

    // The invariant first, so a regression says "the read does not carry the answer" rather than erroring on a missing path.
    expect($read->json('context.answered_escalations.0.answer'))->toBe($answer);
    $read->assertJsonCount(1, 'context.answered_escalations')
        ->assertJsonPath('context.answered_escalations.0.id', $escalation->id)
        ->assertJsonPath('context.answered_escalations.0.question', 'Which way should the worker finish?')
        ->assertJsonPath('context.answered_escalations.0.options.0', 'Option A: finish now')
        ->assertJsonPath('context.answered_escalations.0.options.1', 'Option B: keep going')
        ->assertJsonPath('context.answered_escalations.0.answered_at', CarbonImmutable::now()->toJSON());

    // AC3: the event is only a pointer, so a task woken by one that names ANOTHER task's escalation
    // must still not receive its answer. Without the task scope on the read, this is a cross-task leak.
    $sibling = orchestrationTask();
    $engine->event($sibling, 'sibling-points-elsewhere', 'escalation_answered', ['escalation_id' => $escalation->id], inheritAuthor: false);
    $siblingTurn = orchestrationClaims($sibling, orchestrationHost('sibling-host'))[0];
    $siblingRead = $this->getJson(resolve(WorkWorkerLinks::class)->forItem($siblingTurn)['orchestration'])->assertOk();
    expect($siblingRead->json('context.events.0.kind'))->toBe('escalation_answered');
    $siblingRead->assertJsonCount(0, 'context.answered_escalations');
    expect($siblingRead->getContent())->not->toContain($answer);

    // AC3: and the same holds across teams.
    $otherTeam = Team::query()->create(['name' => 'Other team', 'slug' => 'other-team-'.Str::random(8)]);
    $otherTask = WorkTask::query()->create([
        'team_id' => $otherTeam->id, 'title' => 'Other team task', 'required_capabilities' => [],
        'timebox_minutes' => 10, 'creator_actor' => 'supervisor',
    ]);
    $engine->event($otherTask, 'other-team-points-elsewhere', 'escalation_answered', ['escalation_id' => $escalation->id], inheritAuthor: false);
    $otherTurn = resolve(WorkClaimService::class)->claim($otherTeam->id, 'other-team-host', orchestrationHost('other-team-host')->id, ['orchestrator'], [], 25, CarbonImmutable::now(), 300)[0];
    $otherRead = $this->getJson(resolve(WorkWorkerLinks::class)->forItem($otherTurn)['orchestration'])->assertOk();
    expect($otherRead->json('context.events.0.kind'))->toBe('escalation_answered');
    $otherRead->assertJsonCount(0, 'context.answered_escalations');
    expect($otherRead->getContent())->not->toContain($answer);
});

test('an escalation answered on the Work page reaches the woken turn in the same shape', function (): void {
    $user = capstanUser();
    $actor = (string) $user->id;
    $parent = orchestrationTask();
    $escalation = resolve(WorkOrchestrationService::class)
        ->escalate($parent, 'answer-from-the-page', $actor, 'Page question?', ['Page option'], 'Page option');

    $answer = 'Answered from the Work page.';
    Livewire::actingAs($user)->test(Index::class)
        ->set('answers.'.$escalation->id, $answer)
        ->call('answerEscalation', $escalation->id)
        ->assertHasNoErrors();

    $turn = orchestrationClaims($parent, orchestrationHost('page-answer-host'))[0];
    $read = $this->getJson(resolve(WorkWorkerLinks::class)->forItem($turn)['orchestration'])->assertOk();
    expect($read->json('context.answered_escalations.0.answer'))->toBe($answer);
    $read->assertJsonCount(1, 'context.answered_escalations')
        ->assertJsonPath('context.answered_escalations.0.id', $escalation->id)
        ->assertJsonPath('context.answered_escalations.0.question', 'Page question?')
        ->assertJsonPath('context.answered_escalations.0.options.0', 'Page option');
});
