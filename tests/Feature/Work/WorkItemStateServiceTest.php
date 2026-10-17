<?php

use App\Enums\WorkItemState;
use App\Models\Spoke;
use App\Models\Team;
use App\Models\WorkItem;
use App\Models\WorkTask;
use App\Work\WorkItemStateService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** @param array<string, mixed> $attributes */
function workStateItem(array $attributes = []): WorkItem
{
    $task = WorkTask::query()->create([
        'team_id' => Team::default()->id,
        'title' => 'State service test task',
        'required_capabilities' => [],
        'timebox_minutes' => 20,
        'creator_actor' => 'creator@test',
    ]);

    return $task->items()->create(array_replace(['role' => 'builder'], $attributes));
}

function workStateSpoke(): Spoke
{
    return Spoke::query()->create([
        'actor_id' => 'state-spoke-'.Str::random(8),
        'credential_id' => (string) Str::uuid7(),
        'name' => 'State service spoke',
    ]);
}

test('the normal transition graph appends exactly one matching event per change', function (): void {
    $service = resolve(WorkItemStateService::class);
    $item = workStateItem();
    $spoke = workStateSpoke();
    $at = CarbonImmutable::parse('2026-10-17 12:00:00 UTC');

    $item = $service->claim(
        $item,
        $spoke->id,
        'worker@test',
        $at->addMinutes(10),
        'spoke',
        'worker@test',
        $at,
    );
    $item = $service->transition($item, WorkItemState::Delivered, 'system', 'delivery', $at->addSecond());
    $item = $service->transition($item, WorkItemState::Routed, 'spoke', 'worker@test', $at->addSeconds(2));
    $item = $service->transition($item, WorkItemState::Acted, 'spoke', 'worker@test', $at->addSeconds(3));

    $events = $item->transitions()->get();

    expect($item->state)->toBe(WorkItemState::Acted)
        ->and($item->claimantSpoke?->is($spoke))->toBeTrue()
        ->and($events)->toHaveCount(4)
        ->and($events->pluck('from_state')->all())->toBe([
            WorkItemState::Queued,
            WorkItemState::Claimed,
            WorkItemState::Delivered,
            WorkItemState::Routed,
        ])
        ->and($events->pluck('to_state')->all())->toBe([
            WorkItemState::Claimed,
            WorkItemState::Delivered,
            WorkItemState::Routed,
            WorkItemState::Acted,
        ])
        ->and($events->pluck('actor_kind')->all())->toBe(['spoke', 'system', 'spoke', 'spoke'])
        ->and($events->pluck('fence')->all())->toBe([1, 1, 1, 1])
        ->and($events->first()?->actor_id)->toBe('worker@test')
        ->and($events->first()?->occurred_at->equalTo($at))->toBeTrue();
});

test('routed work may fail and every nonterminal state may be cancelled', function (): void {
    $service = resolve(WorkItemStateService::class);
    $failed = workStateItem(['state' => WorkItemState::Routed]);

    expect($service->transition($failed, WorkItemState::Failed, 'spoke', 'worker@test')->state)
        ->toBe(WorkItemState::Failed);

    foreach ([
        WorkItemState::Queued,
        WorkItemState::Claimed,
        WorkItemState::Delivered,
        WorkItemState::Routed,
    ] as $state) {
        $cancelled = $service->transition(
            workStateItem(['state' => $state]),
            WorkItemState::Cancelled,
            'user',
            'canceller@test',
        );

        expect($cancelled->state)->toBe(WorkItemState::Cancelled)
            ->and($cancelled->transitions()->sole()->to_state)->toBe(WorkItemState::Cancelled);
    }
});

test('illegal and terminal transitions change neither item nor event log', function (): void {
    $service = resolve(WorkItemStateService::class);
    $queued = workStateItem(['last_progress' => 'Test progress']);
    $queued->refresh();
    $before = $queued->getAttributes();

    expect(fn () => $service->transition($queued, WorkItemState::Delivered, 'user', 'actor@test'))
        ->toThrow(DomainException::class);

    expect($queued->fresh()?->getAttributes())->toBe($before)
        ->and($queued->transitions()->count())->toBe(0);

    foreach ([WorkItemState::Acted, WorkItemState::Failed, WorkItemState::Cancelled] as $terminal) {
        $item = workStateItem(['state' => $terminal, 'last_progress' => 'Terminal progress']);
        $item->refresh();
        $terminalBefore = $item->getAttributes();

        expect(fn () => $service->transition($item, WorkItemState::Cancelled, 'user', 'actor@test'))
            ->toThrow(DomainException::class);

        expect($item->fresh()?->getAttributes())->toBe($terminalBefore)
            ->and($item->transitions()->count())->toBe(0);
    }
});

test('release requeues once clears ownership and retains bounded progress', function (): void {
    $service = resolve(WorkItemStateService::class);
    $item = workStateItem([
        'state' => WorkItemState::Routed,
        'claimant_spoke_id' => 987654,
        'claimant_actor' => 'worker@test',
        'lease_until' => now()->addMinute(),
        'claimed_at' => now()->subMinute(),
        'fence' => 8,
        'attempts' => 2,
        'last_progress' => str_repeat('p', 2000),
        'stalled_at' => now(),
    ]);

    $item = $service->release($item, 'spoke', 'worker@test');

    expect($item->state)->toBe(WorkItemState::Queued)
        ->and($item->attempts)->toBe(3)
        ->and($item->claimant_spoke_id)->toBeNull()
        ->and($item->claimant_actor)->toBeNull()
        ->and($item->lease_until)->toBeNull()
        ->and($item->claimed_at)->toBeNull()
        ->and($item->stalled_at)->toBeNull()
        ->and($item->last_progress)->toBe(str_repeat('p', 2000))
        ->and($item->transitions()->count())->toBe(1)
        ->and($item->transitions()->sole()->from_state)->toBe(WorkItemState::Routed)
        ->and($item->transitions()->sole()->to_state)->toBe(WorkItemState::Queued);

    expect(fn () => $service->release($item, 'spoke', 'worker@test'))
        ->toThrow(DomainException::class);

    expect($item->fresh()?->attempts)->toBe(3)
        ->and($item->transitions()->count())->toBe(1);
});

test('expired leases requeue once while live leases remain untouched', function (): void {
    $service = resolve(WorkItemStateService::class);
    $at = CarbonImmutable::parse('2026-10-17 13:00:00 UTC');
    $expired = workStateItem([
        'state' => WorkItemState::Delivered,
        'claimant_spoke_id' => 987654,
        'claimant_actor' => 'worker@test',
        'lease_until' => $at->subSecond(),
        'claimed_at' => $at->subMinutes(5),
        'attempts' => 4,
        'last_progress' => 'Still useful progress',
    ]);

    $expired = $service->expireLease($expired, 'system', 'lease-sweeper', $at);

    expect($expired->state)->toBe(WorkItemState::Queued)
        ->and($expired->attempts)->toBe(5)
        ->and($expired->claimant_spoke_id)->toBeNull()
        ->and($expired->lease_until)->toBeNull()
        ->and($expired->last_progress)->toBe('Still useful progress')
        ->and($expired->transitions()->count())->toBe(1)
        ->and($expired->transitions()->sole()->to_state)->toBe(WorkItemState::Queued)
        ->and($expired->transitions()->sole()->occurred_at->equalTo($at))->toBeTrue();

    $live = workStateItem([
        'state' => WorkItemState::Claimed,
        'lease_until' => $at->addSecond(),
        'attempts' => 1,
    ]);

    expect(fn () => $service->expireLease($live, 'system', 'lease-sweeper', $at))
        ->toThrow(DomainException::class, 'has not expired');

    expect($live->fresh()?->state)->toBe(WorkItemState::Claimed)
        ->and($live->fresh()?->attempts)->toBe(1)
        ->and($live->transitions()->count())->toBe(0);
});
