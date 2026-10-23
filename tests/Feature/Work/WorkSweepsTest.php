<?php

use App\Auth\CapstanCredentialDeclaration;
use App\Enums\SpokeLiveness;
use App\Enums\WorkItemState;
use App\Models\Spoke;
use App\Models\Team;
use App\Models\WorkItem;
use App\Models\WorkTask;
use App\Postmaster\RetireSpoke;
use App\Support\BoundCredentialSpokeResolver;
use App\Work\WorkClaimService;
use App\Work\WorkItemStateService;
use App\Work\WorkNotifier;
use App\Work\WorkSweepService;
use ArtisanBuild\BuiltForCloud\Actions\OffboardSubject;
use ArtisanBuild\BuiltForCloud\OffboardOptions;
use ArtisanBuild\BuiltForCloud\SubjectType;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('s', 32)),
        'capstan.features.work' => true,
        'capstan.postmaster.server_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'capstan.work.missed_heartbeat_seconds' => 180,
        'capstan.work.failover_after_seconds' => 300,
    ]);
    Feature::flushCache();
    Date::setTestNow('2026-10-17 10:00:00');
});

/** @param array<string, mixed> $attributes */
function sweepItem(array $attributes = []): WorkItem
{
    $team = Team::query()->create(['name' => 'Sweep test', 'slug' => (string) Str::uuid7(), 'pool_work' => true]);
    $task = WorkTask::query()->create([
        'team_id' => $team->id, 'title' => 'Sweep task', 'required_capabilities' => [],
        'timebox_minutes' => 10, 'creator_actor' => 'creator',
    ]);
    $spoke = Spoke::query()->create(['actor_id' => 'worker', 'credential_id' => (string) Str::uuid7(), 'last_polled_at' => now()->subMinutes(4)]);

    return $task->items()->create(array_replace([
        'role' => 'builder', 'state' => 'routed', 'fence' => 1,
        'claimant_actor' => 'worker', 'claimant_spoke_id' => $spoke->id,
        'claimed_at' => now()->subMinutes(11), 'lease_until' => now()->addMinutes(5),
    ], $attributes));
}

test('lease expiry is an atomic one time release retaining progress', function (): void {
    $item = sweepItem(['lease_until' => now()->subSecond(), 'claimed_at' => now()->subMinutes(2), 'last_progress' => 'retained']);
    $sweep = resolve(WorkSweepService::class);
    $sweep->liveness(CarbonImmutable::now());
    $sweep->liveness(CarbonImmutable::now());
    expect($item->fresh()->state)->toBe(WorkItemState::Queued)->and($item->fresh()->attempts)->toBe(1)
        ->and($item->fresh()->last_progress)->toBe('retained')->and($item->fresh()->claimed_at)->toBeNull()
        ->and($item->fresh()->claimant_actor)->toBeNull()->and($item->fresh()->lease_until)->toBeNull()
        ->and($item->transitions()->count())->toBe(1)
        ->and($item->transitions()->sole()->actor_id)->toBe('lease-expiry');
});

test('recovery sweeps requeue claims from newly ineligible machines without restoring authority', function (string $lifecycle): void {
    $owner = capstanUser();
    $issued = capstanBoundBearer($owner, CapstanCredentialDeclaration::MACHINE);
    $team = Team::query()->create(['name' => 'Recovery split', 'slug' => 'recovery-split-'.Str::random(8), 'pool_work' => true]);
    $task = WorkTask::query()->create([
        'team_id' => $team->id,
        'title' => 'Recovery authority split',
        'required_capabilities' => [],
        'timebox_minutes' => 30,
        'creator_actor' => (string) $owner->id,
    ]);
    $item = $task->items()->create(['role' => 'builder']);
    $spoke = Spoke::query()->create([
        'actor_id' => (string) $owner->id,
        'credential_id' => $issued['credential']->id,
    ]);
    $claimed = resolve(WorkClaimService::class)->claim(
        $team->id,
        (string) $owner->id,
        $spoke->id,
        ['builder'],
        [],
        1,
        CarbonImmutable::now(),
        60,
    );
    expect($claimed)->toHaveCount(1)
        ->and($claimed[0]->id)->toBe($item->id);
    $observedClaim = $claimed[0];

    if ($lifecycle === 'orphaned') {
        resolve(OffboardSubject::class)(new OffboardOptions(
            SubjectType::UserPrincipal,
            'capstan-user:'.$owner->id,
        ));
        $this->artisan('postmaster:retire-dead-spokes', ['--local' => true])->assertSuccessful();
        expect($spoke->refresh()->orphaned_at)->not->toBeNull()
            ->and($spoke->retired_at)->toBeNull();

        $authorityTime = CarbonImmutable::now()->addSeconds(30);
        $states = resolve(WorkItemStateService::class);
        expect($states->renewClaim(
            $observedClaim, $team->id, $spoke->id, (string) $owner->id,
            $observedClaim->fence, $authorityTime, $authorityTime->addMinute(),
        ))->toBeNull()
            ->and($states->transitionClaim(
                $observedClaim, WorkItemState::Delivered, $team->id, $spoke->id,
                (string) $owner->id, $observedClaim->fence, $authorityTime,
            ))->toBeNull();
    }

    Date::setTestNow(now()->addMinutes(2));
    if ($lifecycle === 'retired') {
        resolve(RetireSpoke::class)->handle($spoke->id, (string) $owner->id, CarbonImmutable::now());
        expect($spoke->refresh()->retired_at)->not->toBeNull();
    }

    $states = resolve(WorkItemStateService::class);
    expect($states->renewClaim(
        $observedClaim, $team->id, $spoke->id, (string) $owner->id,
        $observedClaim->fence, CarbonImmutable::now(), CarbonImmutable::now()->addMinute(),
    ))->toBeNull()
        ->and($states->transitionClaim(
            $observedClaim, WorkItemState::Delivered, $team->id, $spoke->id,
            (string) $owner->id, $observedClaim->fence, CarbonImmutable::now(),
        ))->toBeNull();

    resolve(WorkSweepService::class)->liveness(CarbonImmutable::now());

    $recovered = $item->fresh();
    $recovery = $item->transitions()->where('actor_id', 'lease-expiry')->sole();
    expect($recovered->state)->toBe(WorkItemState::Queued)
        ->and($recovered->claimant_spoke_id)->toBeNull()
        ->and($recovered->claimant_actor)->toBeNull()
        ->and($recovered->claimed_at)->toBeNull()
        ->and($recovered->lease_until)->toBeNull()
        ->and($recovered->attempts)->toBe(1)
        ->and($recovery->from_state)->toBe(WorkItemState::Claimed)
        ->and($recovery->to_state)->toBe(WorkItemState::Queued)
        ->and($recovery->actor_kind)->toBe('system')
        ->and($recovery->actor_id)->toBe('lease-expiry');

    expect(resolve(WorkClaimService::class)->claim(
        $team->id, (string) $owner->id, $spoke->id, ['builder'], [], 1, CarbonImmutable::now(), 300,
    ))->toBe([]);

    $replacementOwner = capstanUser();
    $replacementCredential = capstanBoundBearer($replacementOwner, CapstanCredentialDeclaration::MACHINE);
    $replacement = Spoke::query()->create([
        'actor_id' => (string) $replacementOwner->id,
        'credential_id' => $replacementCredential['credential']->id,
    ]);
    $reclaimed = resolve(WorkClaimService::class)->claim(
        $team->id,
        (string) $replacementOwner->id,
        $replacement->id,
        ['builder'],
        [],
        1,
        CarbonImmutable::now(),
        300,
    );
    expect($reclaimed)->toHaveCount(1)
        ->and($reclaimed[0]->id)->toBe($item->id)
        ->and($reclaimed[0]->claimant_spoke_id)->toBe($replacement->id);
})->with(['orphaned', 'retired']);

test('losing expiry sweep does not requeue a renewed or reclaimed item', function (string $race): void {
    $item = sweepItem(['lease_until' => now()->subSecond(), 'claimed_at' => now()->subMinutes(2)]);
    resolve(WorkSweepService::class)->liveness(CarbonImmutable::now(), function (WorkItem $candidate) use ($race): void {
        WorkItem::query()->whereKey($candidate->id)->update($race === 'renew'
            ? ['lease_until' => now()->addMinutes(5)]
            : ['fence' => 2, 'claimed_at' => now(), 'lease_until' => now()->addMinutes(5)]);
    });
    expect($item->fresh()->state)->toBe(WorkItemState::Routed)->and($item->fresh()->attempts)->toBe(0)
        ->and($item->transitions()->count())->toBe(0);
})->with(['renew', 'reclaim']);

test('a competing sweep wins once even when the loser already read its candidate', function (string $mode): void {
    $item = sweepItem($mode === 'expiry' ? ['lease_until' => now()->subSecond(), 'claimed_at' => now()->subMinute()] : []);
    if ($mode === 'failover') {
        $item->update(['state' => 'queued']);
        $item->forceFill(['updated_at' => now()->subMinutes(6)])->save();
        $item->workTask->update(['device_spoke_id' => $item->claimant_spoke_id, 'failover' => true]);
    }
    $notifier = Mockery::mock(WorkNotifier::class);
    if ($mode === 'stall') {
        $notifier->shouldReceive('stalled')->once();
        $notifier->shouldReceive('missedHeartbeat')->once();
    } else {
        $notifier->shouldNotReceive('stalled', 'missedHeartbeat', 'waitingOnHost');
    }
    app()->instance(WorkNotifier::class, $notifier);
    $sweep = resolve(WorkSweepService::class);
    $method = $mode === 'failover' ? 'failover' : 'liveness';
    $sweep->$method(CarbonImmutable::now(), fn () => $sweep->$method(CarbonImmutable::now()));
    if ($mode === 'stall') {
        expect($item->fresh()->stalled_at)->not->toBeNull();
    } else {
        expect($item->transitions()->count())->toBe(1)
            ->and($item->fresh()->attempts)->toBe($mode === 'expiry' ? 1 : 0);
    }
})->with(['expiry', 'stall', 'failover']);

test('stalls and heartbeat outages mark and notify exactly once with negative controls', function (): void {
    $item = sweepItem();
    $progress = sweepItem();
    $milestone = $progress->milestones()->make(['kind' => 'started', 'fence' => 1, 'payload' => []]);
    $milestone->forceFill(['created_at' => now()->subMinutes(5)])->save();
    $expired = sweepItem(['lease_until' => now()->subSecond(), 'claimed_at' => now()->subMinutes(2)]);
    $fresh = sweepItem(['claimed_at' => now()]);
    $fresh->claimantSpoke->update(['last_polled_at' => now()]);
    $notifier = Mockery::mock(WorkNotifier::class);
    $notifier->shouldReceive('stalled')->once()->withArgs(fn (WorkItem $arg): bool => $arg->id === $item->id);
    $notifier->shouldReceive('missedHeartbeat')->twice()->withArgs(fn (Spoke $arg): bool => in_array($arg->id, [$item->claimant_spoke_id, $progress->claimant_spoke_id]));
    app()->instance(WorkNotifier::class, $notifier);
    $sweep = resolve(WorkSweepService::class);
    $sweep->liveness(CarbonImmutable::now());
    $sweep->liveness(CarbonImmutable::now());
    expect($item->fresh()->stalled_at)->not->toBeNull()->and($progress->fresh()->stalled_at)->toBeNull()
        ->and($fresh->fresh()->stalled_at)->toBeNull()
        ->and(Spoke::query()->find($expired->claimant_spoke_id)->work_heartbeat_notified_at)->toBeNull();
});

test('a current-fence milestone starts a fresh stall deadline while an old-fence milestone does not', function (): void {
    $item = sweepItem(['lease_until' => now()->addMinutes(30)]);
    $item->milestones()->create(['kind' => 'note', 'fence' => 1, 'payload' => []]);
    $second = sweepItem(['fence' => 2]);
    $milestone = $second->milestones()->make(['kind' => 'note', 'fence' => 1, 'payload' => []]);
    $milestone->forceFill(['created_at' => now()->subMinutes(5)])->save();
    $notifier = Mockery::mock(WorkNotifier::class);
    $notifier->shouldReceive('stalled')->twice();
    $notifier->shouldReceive('missedHeartbeat')->twice();
    app()->instance(WorkNotifier::class, $notifier);
    $sweep = resolve(WorkSweepService::class);
    $sweep->liveness(CarbonImmutable::now());
    expect($item->fresh()->stalled_at)->toBeNull()
        ->and($second->fresh()->stalled_at)->not->toBeNull();

    Date::setTestNow(now()->addMinutes(11));
    $sweep->liveness(CarbonImmutable::now());
    expect($item->fresh()->stalled_at)->not->toBeNull();
});

test('a stale stall candidate cannot stamp the replacement fence', function (): void {
    $item = sweepItem();
    $notifier = Mockery::mock(WorkNotifier::class);
    $notifier->shouldNotReceive('stalled');
    $notifier->shouldReceive('missedHeartbeat')->once();
    app()->instance(WorkNotifier::class, $notifier);
    resolve(WorkSweepService::class)->liveness(CarbonImmutable::now(), fn (WorkItem $candidate) => WorkItem::query()->whereKey($candidate->id)->update(['fence' => 2]));
    expect($item->fresh()->stalled_at)->toBeNull();
});

test('heartbeat recovery clears only work notification marker and permits a later outage', function (): void {
    $item = sweepItem(['claimed_at' => now(), 'lease_until' => now()->addHour()]);
    $spoke = $item->claimantSpoke;
    $spoke->update(['probe_status' => SpokeLiveness::Red, 'probe_failed_at' => now()->subHour()]);
    $notifier = Mockery::mock(WorkNotifier::class);
    $notifier->shouldReceive('missedHeartbeat')->twice();
    $notifier->shouldNotReceive('stalled');
    app()->instance(WorkNotifier::class, $notifier);
    $sweep = resolve(WorkSweepService::class);
    $sweep->liveness(CarbonImmutable::now());
    expect($spoke->fresh()->work_heartbeat_notified_at)->not->toBeNull();
    // Keep the stale, pre-notification model: a dirty-attribute-only save
    // would fail to clear a marker written since this instance was loaded.
    resolve(BoundCredentialSpokeResolver::class)->heartbeat($spoke, CarbonImmutable::now());
    expect($spoke->fresh()->work_heartbeat_notified_at)->toBeNull()->and($spoke->fresh()->probe_status)->toBe(SpokeLiveness::Red)
        ->and($spoke->fresh()->probe_failed_at->equalTo(now()->subHour()))->toBeTrue();
    Date::setTestNow(now()->addMinutes(4));
    $sweep->liveness(CarbonImmutable::now());
    $sweep->liveness(CarbonImmutable::now());
});

test('failover drops the task pin once and keeps user and capability constraints with no failback', function (): void {
    $item = sweepItem(['state' => 'queued']);
    $original = $item->claimantSpoke;
    $item->workTask->update(['device_spoke_id' => $original->id, 'failover' => true, 'user_actor_address' => 'worker', 'required_capabilities' => ['php']]);
    $item->forceFill(['updated_at' => now()->subMinutes(6), 'waiting_on_host_at' => now()->subMinute(), 'waiting_on_host_notified_at' => now()->subMinute()])->save();
    $sibling = $item->workTask->items()->create(['role' => 'reviewer', 'waiting_on_host_at' => now(), 'waiting_on_host_notified_at' => now()]);
    $other = Spoke::query()->create(['actor_id' => 'worker', 'credential_id' => (string) Str::uuid7()]);
    $sweep = resolve(WorkSweepService::class);
    $sweep->failover(CarbonImmutable::now());
    $sweep->failover(CarbonImmutable::now());
    expect($item->workTask->fresh()->device_spoke_id)->toBeNull()->and($item->transitions()->count())->toBe(1)
        ->and($item->transitions()->sole()->actor_id)->toBe('device-failover')
        ->and($item->fresh()->waiting_on_host_at)->toBeNull()->and($sibling->fresh()->waiting_on_host_notified_at)->toBeNull();
    $claims = resolve(WorkClaimService::class);
    expect($claims->claim($item->workTask->team_id, 'wrong-user', $other->id, ['builder'], ['php'], 1, CarbonImmutable::now(), 300))->toBe([])
        ->and($claims->claim($item->workTask->team_id, 'worker', $other->id, ['builder'], [], 1, CarbonImmutable::now(), 300))->toBe([]);
    $claimed = $claims->claim($item->workTask->team_id, 'worker', $other->id, ['builder'], ['php'], 1, CarbonImmutable::now(), 300);
    expect($claimed)->toHaveCount(1)->and($claimed[0]->claimant_spoke_id)->toBe($other->id);
    resolve(BoundCredentialSpokeResolver::class)->heartbeat($original, CarbonImmutable::now());
    $sweep->failover(CarbonImmutable::now());
    expect($item->workTask->fresh()->device_spoke_id)->toBeNull();
});

test('non failover pin waits once is invisible to other hosts and clears markers only for successful matching claim', function (): void {
    $item = sweepItem(['state' => 'queued']);
    $originalId = $item->claimant_spoke_id;
    $item->workTask->update(['device_spoke_id' => $originalId, 'failover' => false, 'required_capabilities' => ['php']]);
    $item->forceFill(['updated_at' => now()->subMinutes(6)])->save();
    $notifier = Mockery::mock(WorkNotifier::class);
    $notifier->shouldReceive('waitingOnHost')->once()->withArgs(fn (WorkItem $arg): bool => $arg->id === $item->id);
    app()->instance(WorkNotifier::class, $notifier);
    $sweep = resolve(WorkSweepService::class);
    $sweep->failover(CarbonImmutable::now());
    $first = $item->fresh()->waiting_on_host_notified_at;
    $sweep->failover(CarbonImmutable::now());
    expect($first)->not->toBeNull()->and($item->fresh()->waiting_on_host_notified_at->equalTo($first))->toBeTrue();
    $other = Spoke::query()->create(['actor_id' => 'worker', 'credential_id' => (string) Str::uuid7()]);
    $claims = resolve(WorkClaimService::class);
    expect($claims->claim($item->workTask->team_id, 'worker', $other->id, ['builder'], ['php'], 1, CarbonImmutable::now(), 300))->toBe([])
        ->and($claims->claim($item->workTask->team_id, 'worker', $originalId, ['builder'], [], 1, CarbonImmutable::now(), 300))->toBe([])
        ->and($item->fresh()->waiting_on_host_at)->not->toBeNull();
    expect($claims->claim($item->workTask->team_id, 'worker', $originalId, ['builder'], ['php'], 1, CarbonImmutable::now(), 300))->toHaveCount(1);
    expect($item->fresh()->waiting_on_host_at)->toBeNull()->and($item->fresh()->waiting_on_host_notified_at)->toBeNull()
        ->and($item->workTask->fresh()->device_spoke_id)->toBe($originalId);
});

test('failover respects own queued age cancellation reserved roles and a racing claimant', function (): void {
    foreach (['young', 'cancelled', 'orchestrator', 'human', 'claimed'] as $control) {
        $item = sweepItem(['state' => 'queued', 'role' => in_array($control, ['orchestrator', 'human']) ? $control : 'builder']);
        $item->workTask->update(['device_spoke_id' => $item->claimant_spoke_id, 'failover' => true, 'cancelled_at' => $control === 'cancelled' ? now() : null]);
        $item->forceFill(['updated_at' => $control === 'young' ? now() : now()->subMinutes(6)])->save();
        resolve(WorkSweepService::class)->failover(CarbonImmutable::now(), fn (WorkItem $candidate) => WorkItem::query()->whereKey($candidate->id)->update(['state' => 'claimed', 'fence' => 2]));
        expect($item->workTask->fresh()->device_spoke_id)->not->toBeNull()->and($item->transitions()->count())->toBe(0);
    }
});

test('work schedules run every minute without overlapping', function (): void {
    $events = collect(resolve(Schedule::class)->events())->filter(fn ($event): bool => str_contains($event->command ?? '', 'work:'));
    expect($events)->toHaveCount(4);
    foreach ($events as $event) {
        expect($event->expression)->toBe('* * * * *')->and($event->withoutOverlapping)->toBeTrue();
    }
});
