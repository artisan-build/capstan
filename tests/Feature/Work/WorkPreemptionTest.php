<?php

use App\Auth\CapstanCredentialDeclaration;
use App\Enums\WorkItemState;
use App\Enums\WorkMilestoneKind;
use App\Enums\WorkPriority;
use App\Http\ApiErrorException;
use App\Machine\MachineWorkRuntime;
use App\Models\MachineTeamWorkSetting;
use App\Models\Spoke;
use App\Models\Team;
use App\Models\WorkItem;
use App\Models\WorkOrchestrationDecision;
use App\Models\WorkTask;
use App\Work\WorkClaimService;
use App\Work\WorkDependencyService;
use App\Work\WorkDrainService;
use App\Work\WorkItemStateService;
use App\Work\WorkPreemptionService;
use App\Work\WorkPriorityService;
use App\Work\WorkWorkerLinks;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    Date::setTestNow('2026-10-22 12:00:00');
    config(['capstan.work.preemption_grace_seconds' => 60]);
});

function preemptionTask(User $owner, string $priority = 'P2', ?WorkTask $parent = null): WorkTask
{
    return WorkTask::query()->create([
        'team_id' => Team::default()->id, 'title' => 'Priority test',
        'creator_actor' => (string) $owner->id, 'owner_actor' => (string) $owner->id,
        'parent_task_id' => $parent?->id, 'priority' => $priority,
        'required_capabilities' => [], 'timebox_minutes' => 30,
    ]);
}

function preemptionMachine(User $owner): Spoke
{
    $issued = capstanBoundBearer($owner, CapstanCredentialDeclaration::MACHINE);
    $machine = Spoke::query()->create([
        'actor_id' => (string) $owner->id, 'credential_id' => $issued['credential']->id,
        'credential_backed' => true, 'last_polled_at' => now(), 'name' => 'Test machine',
    ]);
    MachineTeamWorkSetting::query()->create([
        'spoke_id' => $machine->id, 'team_id' => Team::default()->id, 'enabled' => true,
        'roles' => ['builder'], 'capabilities' => [], 'role_providers' => [],
        'concurrency_ceiling' => 1, 'updated_by_actor' => (string) $owner->id,
    ]);
    resolve(MachineWorkRuntime::class)->record(Team::default()->id, (string) $owner->id, $issued['credential']->id, ['builder'], [], [], 1, 0, CarbonImmutable::now());

    return $machine;
}

function preemptionRunning(WorkTask $task, Spoke $machine, string $state = 'claimed'): WorkItem
{
    return $task->items()->create([
        'role' => 'builder', 'state' => $state, 'fence' => 7,
        'claimant_spoke_id' => $machine->id, 'claimant_actor' => $machine->actor_id,
        'claimed_at' => now(), 'lease_until' => now()->addMinutes(5),
        'last_progress' => 'Saved branch capstan/test',
    ]);
}

function preemptionClaim(Spoke $machine): array
{
    return resolve(WorkClaimService::class)->claim(Team::default()->id, $machine->actor_id, $machine->id, ['builder'], [], 1, CarbonImmutable::now(), 300, requireMachineProfile: true);
}

function expectPreemptionWireHasNoNulls(mixed $value): void
{
    if (! is_array($value)) {
        expect($value)->not->toBeNull();

        return;
    }

    foreach ($value as $child) {
        expectPreemptionWireHasNoNulls($child);
    }
}

test('P0 preview is exact read only and confirmation stores one bounded reason and event', function (): void {
    $owner = capstanUser(['role' => 'member']);
    $machine = preemptionMachine($owner);
    $victim = preemptionRunning(preemptionTask($owner, 'P3'), $machine);
    $milestone = $victim->milestones()->create(['kind' => WorkMilestoneKind::Started, 'fence' => 7, 'payload' => []]);
    $root = preemptionTask($owner);
    $child = preemptionTask($owner, parent: $root);
    $child->items()->create(['role' => 'builder']);
    $service = resolve(WorkPriorityService::class);
    $input = ['team_id' => Team::default()->id, 'task_id' => $root->id, 'priority' => 'P0'];
    $before = $victim->fresh()->getRawOriginal();
    $preview = $service->change($input, (string) $owner->id, 'preview');
    expect($preview['priority_changed'])->toBeFalse()
        ->and($preview['preemptions'])->toBe([[
            'item_id' => $victim->id, 'role' => 'builder', 'machine_id' => $machine->id,
            'machine_name' => 'Test machine', 'owner_actor' => (string) $owner->id,
            'milestone_kind' => 'started', 'milestone_at' => $milestone->created_at->toISOString(),
        ]])
        ->and($victim->fresh()->getRawOriginal())->toBe($before)
        ->and($root->fresh()->priority)->toBe(WorkPriority::P2)
        ->and(WorkOrchestrationDecision::query()->count())->toBe(0);
    $result = $service->change([...$input, 'confirmation_token' => $preview['confirmation_token'], 'reason' => 'Incident response'], (string) $owner->id, 'confirmed');
    expect($result['priority_changed'])->toBeTrue()
        ->and($child->fresh()->priority)->toBe(WorkPriority::P0)
        ->and(WorkOrchestrationDecision::query()->sole()->rationale)->toBe('Incident response')
        ->and(WorkOrchestrationDecision::query()->sole()->triggering_event_id)->not->toBeEmpty();
});

test('a changed preemption plan refuses confirmation and returns a fresh token', function (): void {
    $owner = capstanUser();
    $machine = preemptionMachine($owner);
    $victim = preemptionRunning(preemptionTask($owner, 'P3'), $machine);
    $root = preemptionTask($owner);
    $root->items()->create(['role' => 'builder']);
    $input = ['team_id' => Team::default()->id, 'task_id' => $root->id, 'priority' => 'P0'];
    $service = resolve(WorkPriorityService::class);
    $preview = $service->change($input, (string) $owner->id, 'preview');
    $victim->update(['state' => 'routed']);
    $result = $service->change([...$input, 'confirmation_token' => $preview['confirmation_token'], 'reason' => 'Incident'], (string) $owner->id, 'confirm');
    // State is part of the plan even when the selected victim does not change.
    expect($result['confirmation_status'])->toBe('refused')
        ->and($result['confirmation_token'])->not->toBe($preview['confirmation_token'])
        ->and($root->fresh()->priority)->toBe(WorkPriority::P2);
});

test('preview orders an exact multi machine plan with safe names latest milestones and omitted absent fields', function (): void {
    $owner = capstanUser();
    $firstMachine = preemptionMachine($owner);
    $firstMachine->forceFill(['name' => '<unsafe>'])->save();
    $secondMachine = preemptionMachine($owner);
    $secondMachine->forceFill(['name' => null])->save();
    $firstVictim = preemptionRunning(preemptionTask($owner, 'P3'), $firstMachine);
    $secondVictim = preemptionRunning(preemptionTask($owner, 'P2'), $secondMachine);
    $firstVictim->update(['role' => 'builder-a']);
    $secondVictim->update(['role' => 'builder-b']);
    MachineTeamWorkSetting::query()->where('spoke_id', $firstMachine->id)->update(['roles' => ['builder-a']]);
    MachineTeamWorkSetting::query()->where('spoke_id', $secondMachine->id)->update(['roles' => ['builder-b']]);
    resolve(MachineWorkRuntime::class)->record(
        Team::default()->id, (string) $owner->id, $firstMachine->credential_id,
        ['builder-a'], [], [], 0, 1, CarbonImmutable::now(),
    );
    resolve(MachineWorkRuntime::class)->record(
        Team::default()->id, (string) $owner->id, $secondMachine->credential_id,
        ['builder-b'], [], [], 0, 1, CarbonImmutable::now(),
    );
    $latest = $firstVictim->milestones()->make(['kind' => WorkMilestoneKind::Started, 'fence' => 7, 'payload' => []]);
    $latest->forceFill(['created_at' => now()->subMinute()])->save();
    $olderInsertedLater = $firstVictim->milestones()->make(['kind' => WorkMilestoneKind::Note, 'fence' => 7, 'payload' => []]);
    $olderInsertedLater->forceFill(['created_at' => now()->subMinutes(2)])->save();
    $root = preemptionTask($owner);
    $root->items()->createMany([['role' => 'builder-a'], ['role' => 'builder-b']]);

    $preview = resolve(WorkPriorityService::class)->change([
        'team_id' => Team::default()->id, 'task_id' => $root->id, 'priority' => 'P0',
    ], (string) $owner->id, 'multi-machine-preview');

    expect($preview['preemptions'])->toHaveCount(2)
        ->and($preview['preemptions'][0])->toMatchArray([
            'item_id' => $firstVictim->id,
            'machine_id' => $firstMachine->id,
            'machine_name' => 'Machine #'.$firstMachine->id,
            'milestone_kind' => 'started',
            'milestone_at' => $latest->created_at->toISOString(),
        ])
        ->and($preview['preemptions'][1])->toMatchArray([
            'item_id' => $secondVictim->id,
            'machine_id' => $secondMachine->id,
            'machine_name' => 'Machine #'.$secondMachine->id,
        ])
        ->and($preview['preemptions'][1])->not->toHaveKey('milestone_kind')
        ->and($preview['preemptions'][1])->not->toHaveKey('milestone_at');
});

test('preview and release use the same deterministic machine for an owner only opportunity', function (): void {
    $owner = capstanUser();
    $winner = preemptionMachine($owner);
    $other = preemptionMachine($owner);
    $winnerVictim = preemptionRunning(preemptionTask($owner, 'P3'), $winner);
    $otherVictim = preemptionRunning(preemptionTask($owner, 'P3'), $other);
    $root = preemptionTask($owner);
    $opportunity = $root->items()->create(['role' => 'builder']);

    $preview = resolve(WorkPriorityService::class)->change([
        'team_id' => Team::default()->id, 'task_id' => $root->id, 'priority' => 'P0',
    ], (string) $owner->id, 'machine-winner-preview');
    expect($preview['preemptions'])->toHaveCount(1)
        ->and($preview['preemptions'][0]['machine_id'])->toBe($winner->id)
        ->and($preview['preemptions'][0]['item_id'])->toBe($winnerVictim->id);
    resolve(WorkPriorityService::class)->change([
        'team_id' => Team::default()->id, 'task_id' => $root->id, 'priority' => 'P0',
        'confirmation_token' => $preview['confirmation_token'], 'reason' => 'Incident response',
    ], (string) $owner->id, 'machine-winner-confirm');

    expect(preemptionClaim($other))->toBe([])
        ->and($otherVictim->fresh()->release_requested_at)->toBeNull();
    expect(preemptionClaim($winner))->toBe([])
        ->and($winnerVictim->fresh()->release_for_item_id)->toBe($opportunity->id);
});

test('an eligible machine with capacity prevents preemption on another machine', function (): void {
    $owner = capstanUser();
    $full = preemptionMachine($owner);
    $available = preemptionMachine($owner);
    $victim = preemptionRunning(preemptionTask($owner, 'P3'), $full);
    $root = preemptionTask($owner);
    $opportunity = $root->items()->create(['role' => 'builder']);
    $service = resolve(WorkPriorityService::class);
    $input = ['team_id' => Team::default()->id, 'task_id' => $root->id, 'priority' => 'P0'];
    $preview = $service->change($input, (string) $owner->id, 'available-preview');
    expect($preview['preemptions'])->toBe([]);
    $service->change([
        ...$input, 'confirmation_token' => $preview['confirmation_token'], 'reason' => 'Incident response',
    ], (string) $owner->id, 'available-confirm');

    expect(preemptionClaim($full))->toBe([])
        ->and($victim->fresh()->release_requested_at)->toBeNull()
        ->and(preemptionClaim($available)[0]->id)->toBe($opportunity->id);
});

test('victim order is priority then progress then stable item id excluding reserved P0 and dead claims', function (): void {
    $owner = capstanUser();
    $machine = preemptionMachine($owner);
    $p2 = preemptionRunning(preemptionTask($owner, 'P2'), $machine);
    $routed = preemptionRunning(preemptionTask($owner, 'P3'), $machine, 'routed');
    $delivered = preemptionRunning(preemptionTask($owner, 'P3'), $machine, 'delivered');
    $first = preemptionRunning(preemptionTask($owner, 'P3'), $machine);
    $second = preemptionRunning(preemptionTask($owner, 'P3'), $machine);
    $dead = preemptionRunning(preemptionTask($owner, 'P3'), $machine);
    $dead->update(['lease_until' => now()]);
    $reserved = preemptionRunning(preemptionTask($owner, 'P3'), $machine);
    $reserved->update(['role' => WorkItem::ROLE_ORCHESTRATOR]);
    preemptionRunning(preemptionTask($owner, 'P0'), $machine);
    $service = resolve(WorkPreemptionService::class);
    foreach ([$first, $second, $delivered, $routed, $p2] as $expected) {
        expect($service->victim($machine->id, CarbonImmutable::now())->id)->toBe($expected->id);
        $expected->update(['state' => 'acted']);
    }
    expect($service->victim($machine->id, CarbonImmutable::now()))->toBeNull();
});

test('an older queued orchestrator never hides an ordinary P0 preemption opportunity', function (): void {
    $owner = capstanUser();
    $machine = preemptionMachine($owner);
    $victim = preemptionRunning(preemptionTask($owner, 'P3'), $machine);
    $p0 = preemptionTask($owner, 'P0');
    $p0->items()->create(['role' => WorkItem::ROLE_ORCHESTRATOR]);
    $ordinary = $p0->items()->create(['role' => 'builder']);

    expect(preemptionClaim($machine))->toBe([])
        ->and($victim->fresh()->release_for_item_id)->toBe($ordinary->id);
});

test('full machine requests one release and cooperative release preserves handoff and allows P0 claim', function (): void {
    $owner = capstanUser();
    $recipient = capstanUser();
    $machine = preemptionMachine($owner);
    $task = preemptionTask($owner, 'P3');
    $task->update(['escalation_actor' => (string) $recipient->id]);
    $victim = preemptionRunning($task, $machine, 'routed');
    $p0 = preemptionTask($owner, 'P0')->items()->create(['role' => 'builder']);
    Log::spy();
    $drain = resolve(WorkDrainService::class);
    $claim = fn (int $capacity): array => $drain->claim(
        Team::default()->id, (string) $owner->id, $machine->credential_id, $capacity,
        ['builder'], [], [], 1, CarbonImmutable::now(),
    );
    expect($claim(0))->toBe([]);
    expect($claim(0))->toBe([]);
    expect($victim->fresh()->release_for_item_id)->toBe($p0->id)
        ->and($victim->fresh()->release_deadline->toISOString())->toBe(now()->addSeconds(60)->toISOString())
        ->and($recipient->fresh()->notifications)->toHaveCount(1)
        ->and($owner->fresh()->notifications)->toHaveCount(0);
    $notification = $recipient->fresh()->notifications->sole();
    expect(array_keys($notification->data))->toBe(['kind', 'task_id', 'item_id', 'fence', 'escalation_actor', 'state']);
    $links = resolve(WorkWorkerLinks::class)->forItem($victim->fresh());
    $brief = $this->getJson($links['brief'])->assertOk()->assertJsonPath('release_requested.reason', 'P0_preemption')->json();
    $steers = $this->getJson($links['steers'])->assertOk()->assertJsonPath('release_requested.reason', 'P0_preemption')->json();
    expectPreemptionWireHasNoNulls($brief);
    expectPreemptionWireHasNoNulls($steers);
    $drain->transition(
        Team::default()->id, (string) $owner->id, $machine->credential_id,
        $victim->id, 7, 'released', CarbonImmutable::now(),
    );
    expect($victim->fresh()->state)->toBe(WorkItemState::Queued)
        ->and($victim->fresh()->attempts)->toBe(1)
        ->and($victim->fresh()->release_requested_at)->toBeNull()
        ->and($victim->fresh()->last_progress)->toBe('Saved branch capstan/test')
        ->and($task->fresh()->priority)->toBe(WorkPriority::P3);
    expect(fn () => $drain->transition(
        Team::default()->id, (string) $owner->id, $machine->credential_id,
        $victim->id, 7, 'released', CarbonImmutable::now(),
    ))->toThrow(ApiErrorException::class);
    expect($claim(1)[0]->id)->toBe($p0->id);
    Log::shouldHaveReceived('warning')->with('Work item preempted.', Mockery::on(fn (array $context): bool => $context['escalation_actor'] === (string) $recipient->id))->once();
});

test('malformed legacy escalation actors fall back to the owner without breaking timeout sweep', function (): void {
    $owner = capstanUser();
    $machine = preemptionMachine($owner);
    $task = preemptionTask($owner, 'P3');
    $task->forceFill(['escalation_actor' => 'legacy-actor'])->save();
    $victim = preemptionRunning($task, $machine);
    preemptionTask($owner, 'P0')->items()->create(['role' => 'builder']);

    expect(preemptionClaim($machine))->toBe([])
        ->and($owner->fresh()->notifications)->toHaveCount(1)
        ->and($owner->fresh()->notifications->sole()->data['escalation_actor'])->toBe((string) $owner->id);
    resolve(WorkPreemptionService::class)->sweep(CarbonImmutable::now()->addSeconds(60));
    expect($victim->fresh()->state)->toBe(WorkItemState::Queued)
        ->and($victim->fresh()->fence)->toBe(8);
});

test('timeout fences immediately frees capacity and rejects stale renew and transition exactly once', function (): void {
    $owner = capstanUser();
    $machine = preemptionMachine($owner);
    $victim = preemptionRunning(preemptionTask($owner, 'P3'), $machine);
    $p0 = preemptionTask($owner, 'P0')->items()->create(['role' => 'builder']);
    preemptionClaim($machine);
    $sweep = resolve(WorkPreemptionService::class);
    $sweep->sweep(CarbonImmutable::now()->addSeconds(59));
    expect($victim->fresh()->fence)->toBe(7);
    Date::setTestNow(now()->addSeconds(60));
    $sweep->sweep(CarbonImmutable::now());
    $sweep->sweep(CarbonImmutable::now());
    expect($victim->fresh()->fence)->toBe(8)
        ->and($victim->fresh()->attempts)->toBe(1)
        ->and($victim->fresh()->release_requested_at)->toBeNull()
        ->and($victim->transitions()->count())->toBe(1);
    $states = resolve(WorkItemStateService::class);
    expect($states->renewClaim($victim, Team::default()->id, $machine->id, $machine->actor_id, 7, CarbonImmutable::now(), CarbonImmutable::now()->addMinutes(5)))->toBeNull()
        ->and($states->transitionClaim($victim, WorkItemState::Delivered, Team::default()->id, $machine->id, $machine->actor_id, 7, CarbonImmutable::now()))->toBeNull();
    expect(preemptionClaim($machine)[0]->id)->toBe($p0->id);
});

test('notification storage failure rolls back release request and retry does not duplicate notification', function (): void {
    $owner = capstanUser();
    $machine = preemptionMachine($owner);
    $victim = preemptionRunning(preemptionTask($owner, 'P3'), $machine);
    preemptionTask($owner, 'P0')->items()->create(['role' => 'builder']);
    DB::statement('ALTER TABLE notifications RENAME TO unavailable_notifications');
    try {
        expect(fn () => preemptionClaim($machine))->toThrow(QueryException::class);
    } finally {
        DB::statement('ALTER TABLE unavailable_notifications RENAME TO notifications');
    }
    expect($victim->fresh()->release_requested_at)->toBeNull();
    preemptionClaim($machine);
    preemptionClaim($machine);
    expect($owner->fresh()->notifications)->toHaveCount(1);
});

test('ineligible P0 never requests release', function (string $gate): void {
    $owner = capstanUser();
    $machine = preemptionMachine($owner);
    $victim = preemptionRunning(preemptionTask($owner, 'P3'), $machine);
    $root = preemptionTask($owner, 'P0');
    $item = $root->items()->create(['role' => 'builder']);
    if ($gate === 'dependency') {
        $dependency = preemptionTask($owner);
        resolve(WorkDependencyService::class)->replace($root, [$dependency->id]);
    } elseif ($gate === 'expiry') {
        $root->update(['expires_at' => now()]);
    } elseif ($gate === 'capabilities') {
        $root->update(['required_capabilities' => ['missing']]);
    } elseif ($gate === 'role') {
        $item->update(['role' => 'reviewer']);
    } elseif ($gate === 'owner') {
        $root->update(['user_actor_address' => (string) capstanUser()->id]);
    } elseif ($gate === 'pin') {
        $root->update(['device_spoke_id' => preemptionMachine($owner)->id]);
    }
    expect(preemptionClaim($machine))->toBe([])
        ->and($victim->fresh()->release_requested_at)->toBeNull()
        ->and(DB::table('notifications')->count())->toBe(0);
})->with(['dependency', 'expiry', 'capabilities', 'role', 'owner', 'pin']);
