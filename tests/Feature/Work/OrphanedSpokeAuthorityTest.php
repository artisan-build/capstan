<?php

use App\Http\ApiErrorException;
use App\Models\Spoke;
use App\Models\Team;
use App\Models\WorkItem;
use App\Models\WorkOrchestratorSeat;
use App\Models\WorkTask;
use App\Work\WorkClaimService;
use App\Work\WorkOrchestrationMcpService;
use App\Work\WorkOrchestrationSurface;
use App\Work\WorkSeatService;
use App\Work\WorkToolService;
use App\Work\WorkWorkerLinks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config(['capstan.features.work' => true, 'capstan.work.link_grace_seconds' => 60]);
    Feature::flushCache();
});

/** @return array{team: Team, task: WorkTask, spoke: Spoke, item: WorkItem} */
function orphanAuthorityFixture(string $role = 'builder'): array
{
    $team = Team::query()->create(['name' => 'Orphan authority', 'slug' => 'orphan-authority-'.Str::random(8)]);
    $task = WorkTask::query()->create([
        'team_id' => $team->id,
        'title' => 'Orphan authority task',
        'required_capabilities' => [],
        'timebox_minutes' => 30,
        'creator_actor' => '101',
    ]);
    $spoke = Spoke::query()->create([
        'actor_id' => '101',
        'credential_id' => (string) Str::uuid7(),
    ]);
    $item = $task->items()->create([
        'role' => $role,
        'state' => 'routed',
        'claimant_spoke_id' => $spoke->id,
        'claimant_actor' => $spoke->actor_id,
        'lease_until' => now()->addMinutes(5),
        'claimed_at' => now(),
        'fence' => 1,
    ]);

    return compact('team', 'task', 'spoke', 'item');
}

test('orphaning invalidates every already-issued signed worker link without effects', function (): void {
    $fixture = orphanAuthorityFixture();
    $fixture['task']->briefRevisions()->create(['sequence' => 1, 'content' => 'Active brief', 'author_actor' => '101']);
    $steer = $fixture['task']->steers()->create(['work_item_id' => $fixture['item']->id, 'text' => 'Unread', 'author_actor' => '102']);
    $links = resolve(WorkWorkerLinks::class)->forItem($fixture['item']);

    $this->getJson($links['brief'])->assertOk()->assertJsonPath('item.id', $fixture['item']->id);
    $fixture['item']->forceFill(['fetched_brief_revision_id' => null])->save();
    $fixture['spoke']->forceFill(['orphaned_at' => now()])->save();

    $this->getJson($links['brief'])->assertConflict()->assertSeeText('claim lost');
    $this->getJson($links['steers'])->assertConflict()->assertSeeText('claim lost');
    $this->postJson($links['milestone'], ['kind' => 'note', 'payload' => ['summary' => 'must not persist']])
        ->assertConflict()->assertSeeText('claim lost');

    expect($fixture['item']->refresh()->fetched_brief_revision_id)->toBeNull()
        ->and($fixture['item']->last_progress)->toBeNull()
        ->and($fixture['item']->milestones()->count())->toBe(0)
        ->and($steer->fresh()->read_at)->toBeNull();
});

test('orphaning invalidates signed orchestration reads and actions', function (): void {
    $fixture = orphanAuthorityFixture(WorkItem::ROLE_ORCHESTRATOR);
    $fixture['item']->forceFill(['seat_fence' => 1, 'turn_cursor' => 0, 'turn_upper_bound' => 0])->save();
    WorkOrchestratorSeat::query()->create([
        'work_task_id' => $fixture['task']->id,
        'holder_actor' => $fixture['spoke']->actor_id,
        'holder_spoke_id' => $fixture['spoke']->id,
        'session_ref' => 'orphan-session',
        'interactive' => false,
        'lease_until' => now()->addMinutes(5),
        'fence' => 1,
        'active_item_id' => $fixture['item']->id,
        'event_sequence' => 0,
        'completed_cursor' => 0,
    ]);
    $links = resolve(WorkWorkerLinks::class)->forItem($fixture['item']);

    $this->getJson($links['orchestration'])->assertOk()->assertJsonPath('context.task.id', $fixture['task']->id);
    $fixture['spoke']->forceFill(['orphaned_at' => now()])->save();

    $this->getJson($links['orchestration'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'orchestration_forbidden');
    $this->postJson($links['orchestrate'], [
        'action' => 'finish',
        'input' => ['state' => 'acted', 'content' => 'must not persist'],
        'idempotency_key' => 'orphaned-signed-action',
    ])->assertForbidden()->assertJsonPath('error.code', 'orchestration_forbidden');

    expect($fixture['item']->refresh()->state->value)->toBe('routed');
});

test('orphaned spokes cannot claim or continue seats while equivalent active spokes work', function (): void {
    $active = orphanAuthorityFixture();
    $active['item']->forceFill([
        'state' => 'queued', 'claimant_spoke_id' => null, 'claimant_actor' => null,
        'lease_until' => null, 'claimed_at' => null, 'fence' => 0,
    ])->save();
    $claimed = resolve(WorkClaimService::class)->claim(
        $active['team']->id,
        $active['spoke']->actor_id,
        $active['spoke']->id,
        ['builder'],
        [],
        1,
        CarbonImmutable::now(),
        300,
    );
    expect($claimed)->toHaveCount(1);
    $queuedAfterOrphan = $active['task']->items()->create(['role' => 'builder']);

    $seatTask = WorkTask::query()->create([
        'team_id' => $active['team']->id, 'title' => 'Seat task', 'required_capabilities' => [],
        'timebox_minutes' => 30, 'creator_actor' => $active['spoke']->actor_id,
    ]);
    $seats = resolve(WorkSeatService::class);
    $seat = $seats->take($seatTask, $active['spoke']->actor_id, $active['spoke']->id, 0, 'active', true, CarbonImmutable::now());
    expect($seat)->toBeInstanceOf(WorkOrchestratorSeat::class);

    $active['spoke']->forceFill(['orphaned_at' => now()])->save();
    expect(resolve(WorkClaimService::class)->claim(
        $active['team']->id, $active['spoke']->actor_id, $active['spoke']->id,
        ['builder'], [], 1, CarbonImmutable::now(), 300,
    ))->toBe([])
        ->and($queuedAfterOrphan->refresh()->state->value)->toBe('queued')
        ->and($seats->eligible($seat, $active['spoke']->actor_id, $active['spoke']->id, CarbonImmutable::now()))->toBeFalse();
    expect(fn () => $seats->renew($seatTask, $active['spoke']->actor_id, $active['spoke']->id, $seat->fence, 'orphaned', CarbonImmutable::now()))
        ->toThrow(DomainException::class, 'Work host is unavailable.')
        ->and(fn () => $seats->release($seatTask, $active['spoke']->actor_id, $active['spoke']->id, $seat->fence, CarbonImmutable::now()))
        ->toThrow(DomainException::class, 'Work host is unavailable.');
});

test('orphaned spokes cannot be newly pinned through Work or orchestration surfaces', function (): void {
    $fixture = orphanAuthorityFixture();
    $owner = capstanUser();
    $fixture['spoke']->forceFill(['actor_id' => (string) $owner->id])->save();
    $activeInput = [
        'team_id' => $fixture['team']->id,
        'title' => 'Pinned active task',
        'brief' => 'Pinned active brief',
        'device_spoke_id' => $fixture['spoke']->id,
        'failover' => true,
        'required_capabilities' => [],
        'timebox_minutes' => 30,
    ];
    $created = resolve(WorkToolService::class)->write('create_task', $activeInput, '101');
    expect(data_get($created, 'task.device_spoke_id'))->toBe($fixture['spoke']->id);
    expect(resolve(WorkOrchestrationSurface::class)->validate('create_child', [
        'under_task_id' => $fixture['task']->id,
        'title' => 'Active child',
        'brief' => 'Active child brief',
        'device_spoke_id' => $fixture['spoke']->id,
        'failover' => true,
    ]))->toHaveKey('device_spoke_id');

    $fixture['spoke']->forceFill(['orphaned_at' => now()])->save();
    expect(fn () => resolve(WorkToolService::class)->write('create_task', [
        ...$activeInput, 'title' => 'Orphaned pin',
    ], '101'))->toThrow(ApiErrorException::class, 'Work host is unavailable.')
        ->and(fn () => resolve(WorkOrchestrationSurface::class)->validate('create_child', [
            'under_task_id' => $fixture['task']->id,
            'title' => 'Orphaned child',
            'brief' => 'Orphaned child brief',
            'device_spoke_id' => $fixture['spoke']->id,
            'failover' => true,
        ]))->toThrow(ApiErrorException::class);
});

test('MCP orchestration cannot resolve an explicit orphaned spoke', function (): void {
    $fixture = orphanAuthorityFixture();
    $fixture['spoke']->forceFill(['orphaned_at' => now()])->save();

    expect(fn () => resolve(WorkOrchestrationMcpService::class)->take(
        $fixture['team']->id,
        $fixture['task']->id,
        $fixture['spoke']->actor_id,
        $fixture['spoke']->credential_id,
        $fixture['spoke']->id,
        'orphaned-mcp',
        0,
        CarbonImmutable::now(),
        credentialBacked: false,
    ))->toThrow(ApiErrorException::class, 'The requested Work orchestration operation is unavailable.');
});
