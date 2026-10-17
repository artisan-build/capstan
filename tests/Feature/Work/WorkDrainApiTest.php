<?php

use App\Auth\CapstanCredentialDeclaration;
use App\Enums\WorkItemState;
use App\Models\Inbox;
use App\Models\Spoke;
use App\Models\Team;
use App\Models\WorkItem;
use App\Models\WorkTask;
use App\Work\WorkItemStateService;
use App\Work\WorkOrchestrationService;
use App\Work\WorkSweepService;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config([
        'capstan.features.work' => true,
        'capstan.work.lease_seconds' => 300,
    ]);
    Feature::flushCache();
});

/** @param array<string, mixed> $attributes */
function workDrainTeam(array $attributes = []): Team
{
    $suffix = Str::lower(Str::random(8));

    return Team::query()->create(array_replace([
        'name' => 'Work drain team '.$suffix,
        'slug' => 'work-drain-'.$suffix,
        'is_default' => false,
    ], $attributes));
}

/** @param array<string, mixed> $attributes */
function workDrainTask(Team $team, array $attributes = []): WorkTask
{
    return WorkTask::query()->create(array_replace([
        'team_id' => $team->id,
        'title' => 'Test-created drain task '.Str::random(8),
        'required_capabilities' => [],
        'timebox_minutes' => 30,
        'creator_actor' => 'test-creator',
    ], $attributes));
}

/** @param array<string, mixed> $attributes */
function workDrainItem(WorkTask $task, array $attributes = []): WorkItem
{
    return $task->items()->create(array_replace(['role' => 'builder'], $attributes));
}

/** @return array{token: string, credential: Credential, headers: array<string, string>} */
function workDrainCredential(User $user): array
{
    $issued = capstanBoundBearer($user, CapstanCredentialDeclaration::WORK_DRAIN);

    return [
        ...$issued,
        'headers' => [
            'Authorization' => 'Bearer '.$issued['token'],
            CapstanCredentialDeclaration::ACTOR_HEADER => (string) $user->getKey(),
        ],
    ];
}

/**
 * @param  list<string>  $roles
 * @param  list<string>  $capabilities
 * @return array{capacity: int, roles: list<string>, capabilities: list<string>}
 */
function workDrainClaimPayload(int $capacity = 1, array $roles = ['builder'], array $capabilities = []): array
{
    return compact('capacity', 'roles', 'capabilities');
}

test('claim is deterministic capped thin and supports zero capacity', function (): void {
    Date::setTestNow('2026-10-17 10:00:00');
    $team = workDrainTeam();
    $task = workDrainTask($team);
    $items = collect();

    foreach (range(1, 27) as $index) {
        Date::setTestNow(CarbonImmutable::parse('2026-10-17 10:00:00')->addSeconds($index));
        $items->push(workDrainItem($task));
    }

    $user = capstanUser();
    $issued = workDrainCredential($user);
    Date::setTestNow('2026-10-17 11:00:00');
    $response = $this->withHeaders($issued['headers'])
        ->postJson(route('api.work.claim', $team), workDrainClaimPayload(25))
        ->assertOk()
        ->assertJsonCount(25, 'items');

    expect($response->json('items.*.id'))->toBe($items->take(25)->pluck('id')->all())
        ->and($response->json('items.0'))->toHaveKeys(['id', 'task_id', 'role', 'fence', 'lease_until'])
        ->and(array_keys($response->json('items.0')))->toBe(['id', 'task_id', 'role', 'fence', 'lease_until', 'links'])
        ->and(json_encode($response->json(), JSON_THROW_ON_ERROR))->not->toContain('null')
        ->and(WorkItem::query()->where('state', WorkItemState::Claimed)->count())->toBe(25)
        ->and(WorkItem::query()->where('state', WorkItemState::Queued)->count())->toBe(2)
        ->and($response->json('items.0.lease_until'))->toBe('2026-10-17T11:05:00.000000Z');

    $this->withHeaders($issued['headers'])
        ->postJson(route('api.work.claim', $team), workDrainClaimPayload(0))
        ->assertOk()
        ->assertExactJson(['items' => []]);

    expect(WorkItem::query()->where('state', WorkItemState::Queued)->count())->toBe(2);
});

test('claim and renewed records carry fresh signed links with capped expiry', function (): void {
    Date::setTestNow('2026-10-17 10:00:00');
    config(['capstan.work.link_grace_seconds' => 60]);
    $team = workDrainTeam();
    $item = workDrainItem(workDrainTask($team, ['timebox_minutes' => 6]));
    $issued = workDrainCredential(capstanUser());
    $claim = $this->withHeaders($issued['headers'])->postJson(route('api.work.claim', $team), workDrainClaimPayload())->assertOk();
    $links = $claim->json('items.0.links');
    expect(array_keys($links))->toBe(['brief', 'milestone', 'steers']);
    Date::setTestNow(now()->addMinutes(2));
    $renew = $this->withHeaders($issued['headers'])->postJson(route('api.work.renew', $team), [
        'items' => [['item_id' => $item->id, 'fence' => 1]],
    ])->assertOk();
    foreach ($renew->json('results.0.item.links') as $action => $url) {
        expect($url)->not->toBe($links[$action]);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        expect((int) $query['expires'])->toBe(CarbonImmutable::parse('2026-10-17 10:07:00')->timestamp);
    }
    $this->getJson($renew->json('results.0.item.links.brief'))->assertOk();
});

test('orchestrator claim and renew return usable signed context and action links', function (): void {
    Date::setTestNow('2026-10-17 10:00:00');
    $team = workDrainTeam();
    $task = workDrainTask($team);
    $task->briefRevisions()->create(['sequence' => 1, 'content' => 'Orchestrator brief', 'author_actor' => 'creator']);
    resolve(WorkOrchestrationService::class)->event($task, 'drain-link-context', 'test_event', ['safe' => true]);
    $issued = workDrainCredential(capstanUser());

    $claim = $this->withHeaders($issued['headers'])
        ->postJson(route('api.work.claim', $team), workDrainClaimPayload(1, ['orchestrator']))->assertOk();
    expect(array_keys($claim->json('items.0.links')))->toBe(['brief', 'milestone', 'steers', 'orchestration', 'orchestrate']);
    $this->getJson($claim->json('items.0.links.orchestration'))->assertOk()
        ->assertJsonPath('context.task.id', $task->id)
        ->assertJsonPath('context.brief.content', 'Orchestrator brief')
        ->assertJsonCount(1, 'context.events');

    $renew = $this->withHeaders($issued['headers'])->postJson(route('api.work.renew', $team), [
        'items' => [['item_id' => $claim->json('items.0.id'), 'fence' => $claim->json('items.0.fence')]],
    ])->assertOk()->assertJsonPath('results.0.status', 'renewed');
    expect(array_keys($renew->json('results.0.item.links')))->toBe(['brief', 'milestone', 'steers', 'orchestration', 'orchestrate']);
    $this->getJson($renew->json('results.0.item.links.orchestration'))->assertOk();
});

test('expired claim links lose their fence after sweep and reclaim while replacement links succeed', function (): void {
    Date::setTestNow('2026-10-17 10:00:00');
    config(['capstan.work.link_grace_seconds' => 60]);
    $team = workDrainTeam();
    $task = workDrainTask($team, ['timebox_minutes' => 60]);
    $item = workDrainItem($task);
    $task->briefRevisions()->create([
        'sequence' => 1,
        'content' => 'First brief',
        'author_actor' => 'creator',
    ]);
    $firstHost = workDrainCredential(capstanUser());
    $secondHost = workDrainCredential(capstanUser());
    $firstClaim = $this->withHeaders($firstHost['headers'])
        ->postJson(route('api.work.claim', $team), workDrainClaimPayload())
        ->assertOk();
    $firstLinks = $firstClaim->json('items.0.links');

    $this->withHeaders($firstHost['headers'])->postJson(route('api.work.transition', $team), [
        'item_id' => $item->id,
        'fence' => 1,
        'state' => 'delivered',
    ])->assertOk();

    Date::setTestNow(now()->addSeconds(330));
    $this->getJson($firstLinks['brief'])->assertOk();
    resolve(WorkSweepService::class)->liveness(CarbonImmutable::now());
    expect($item->fresh()->state)->toBe(WorkItemState::Queued);

    $secondClaim = $this->withHeaders($secondHost['headers'])
        ->postJson(route('api.work.claim', $team), workDrainClaimPayload())
        ->assertOk()
        ->assertJsonPath('items.0.fence', 2);
    $secondLinks = $secondClaim->json('items.0.links');
    $latest = $task->briefRevisions()->create([
        'sequence' => 2,
        'content' => 'Replacement brief',
        'author_actor' => 'creator',
    ]);

    $this->getJson($firstLinks['brief'])
        ->assertConflict()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSeeText('claim lost');
    $this->getJson($firstLinks['steers'])
        ->assertConflict()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSeeText('claim lost');
    $this->postJson($firstLinks['milestone'], [
        'kind' => 'note',
        'payload' => (object) ['summary' => 'stale'],
    ])->assertConflict()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSeeText('claim lost');

    $this->getJson($secondLinks['brief'])
        ->assertOk()
        ->assertJsonPath('revision.id', $latest->id)
        ->assertJsonPath('revision.content', 'Replacement brief');
    $this->getJson($secondLinks['steers'])->assertOk();
    $this->postJson($secondLinks['milestone'], [
        'kind' => 'started',
        'payload' => (object) [],
    ])->assertCreated();

    expect($item->fresh()->fetched_brief_revision_id)->toBe($latest->id)
        ->and($item->milestones()->count())->toBe(1);
});

test('claim validates malformed duplicate and oversized declarations without effects', function (array $payload, string $error): void {
    $team = workDrainTeam();
    $item = workDrainItem(workDrainTask($team));
    $issued = workDrainCredential(capstanUser());

    $this->withHeaders($issued['headers'])
        ->postJson(route('api.work.claim', $team), $payload)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonValidationErrors($error, 'error.errors');

    expect($item->fresh()?->state)->toBe(WorkItemState::Queued)
        ->and(Spoke::query()->count())->toBe(0);
})->with([
    'capacity above cap' => [workDrainClaimPayload(26), 'capacity'],
    'negative capacity' => [workDrainClaimPayload(-1), 'capacity'],
    'empty roles' => [workDrainClaimPayload(1, []), 'roles'],
    'duplicate roles' => [workDrainClaimPayload(1, ['builder', 'builder']), 'roles.1'],
    'too many roles' => [workDrainClaimPayload(1, array_map(fn (int $i): string => "role-$i", range(1, 26))), 'roles'],
    'duplicate capabilities' => [workDrainClaimPayload(1, ['builder'], ['php', 'php']), 'capabilities.1'],
    'too many capabilities' => [workDrainClaimPayload(1, ['builder'], array_map(fn (int $i): string => "cap-$i", range(1, 26))), 'capabilities'],
]);

test('claim applies every routing capability cancellation and reserved role constraint', function (): void {
    $team = workDrainTeam();
    $otherTeam = workDrainTeam();
    $user = capstanUser();
    $otherUser = capstanUser();
    $issued = workDrainCredential($user);
    $callerSpoke = Spoke::query()->create([
        'actor_id' => (string) $user->getKey(),
        'credential_id' => $issued['credential']->id,
    ]);
    $otherSpoke = Spoke::query()->create([
        'actor_id' => (string) $otherUser->getKey(),
        'credential_id' => (string) Str::uuid7(),
    ]);

    $eligible = workDrainItem(workDrainTask($team, [
        'user_actor_address' => (string) $user->getKey(),
        'device_spoke_id' => $callerSpoke->id,
        'required_capabilities' => ['php', 'git'],
    ]));
    $orchestrator = workDrainItem(workDrainTask($team), ['role' => WorkItem::ROLE_ORCHESTRATOR]);
    $excluded = collect([
        workDrainItem(workDrainTask($team), ['role' => 'security']),
        workDrainItem(workDrainTask($team, ['user_actor_address' => (string) $otherUser->getKey()])),
        workDrainItem(workDrainTask($team, ['device_spoke_id' => $otherSpoke->id])),
        workDrainItem(workDrainTask($team, ['required_capabilities' => ['php', 'docker']])),
        workDrainItem(workDrainTask($otherTeam)),
        workDrainItem(workDrainTask($team), ['role' => WorkItem::ROLE_HUMAN]),
        workDrainItem(workDrainTask($team), ['state' => WorkItemState::Cancelled]),
        workDrainItem(workDrainTask($team, ['cancelled_at' => now()])),
    ]);

    $response = $this->withHeaders($issued['headers'])
        ->postJson(route('api.work.claim', $team), workDrainClaimPayload(
            25,
            ['builder', WorkItem::ROLE_ORCHESTRATOR, WorkItem::ROLE_HUMAN],
            ['php', 'git'],
        ))
        ->assertOk();

    expect($response->json('items.*.id'))->toBe([$eligible->id, $orchestrator->id])
        ->and($excluded->map(fn (WorkItem $item) => $item->fresh()?->state)->all())->toBe([
            WorkItemState::Queued,
            WorkItemState::Queued,
            WorkItemState::Queued,
            WorkItemState::Queued,
            WorkItemState::Queued,
            WorkItemState::Queued,
            WorkItemState::Cancelled,
            WorkItemState::Queued,
        ]);
});

test('all drain calls heartbeat one credential spoke without changing Postmaster routing state', function (): void {
    $team = workDrainTeam();
    $item = workDrainItem(workDrainTask($team));
    $user = capstanUser();
    $issued = workDrainCredential($user);
    $spoke = Spoke::query()->create([
        'actor_id' => (string) $user->getKey(),
        'credential_id' => $issued['credential']->id,
        'last_cursor' => 'postmaster-cursor',
    ]);
    $inbox = Inbox::query()->create([
        'actor_id' => (string) $user->getKey(),
        'local_part' => 'work-heartbeat-test',
    ]);
    $spoke->inboxes()->attach($inbox->id);

    Date::setTestNow('2026-10-17 12:00:00');
    $claim = $this->withHeaders($issued['headers'])
        ->postJson(route('api.work.claim', $team), workDrainClaimPayload())
        ->assertOk();
    expect($spoke->fresh()?->last_polled_at?->toDateTimeString())->toBe('2026-10-17 12:00:00');

    Date::setTestNow('2026-10-17 12:01:00');
    $this->withHeaders($issued['headers'])->postJson(route('api.work.transition', $team), [
        'item_id' => $item->id,
        'fence' => $claim->json('items.0.fence'),
        'state' => 'delivered',
    ])->assertOk();
    expect($spoke->fresh()?->last_polled_at?->toDateTimeString())->toBe('2026-10-17 12:01:00');

    Date::setTestNow('2026-10-17 12:02:00');
    $this->withHeaders($issued['headers'])->postJson(route('api.work.renew', $team), [
        'items' => [['item_id' => $item->id, 'fence' => $claim->json('items.0.fence')]],
    ])->assertOk()->assertJsonPath('results.0.status', 'renewed');

    $spoke->refresh();
    expect(Spoke::query()->count())->toBe(1)
        ->and($spoke->last_polled_at?->toDateTimeString())->toBe('2026-10-17 12:02:00')
        ->and($spoke->last_cursor)->toBe('postmaster-cursor')
        ->and($spoke->inboxes()->pluck('inboxes.id')->all())->toBe([$inbox->id])
        ->and($spoke->probes()->count())->toBe(0);
});

test('legal drain transitions append events and release preserves progress while requeueing', function (): void {
    $team = workDrainTeam();
    $task = workDrainTask($team);
    $completed = workDrainItem($task);
    $released = workDrainItem($task, ['last_progress' => 'Test-created progress']);
    $issued = workDrainCredential(capstanUser());

    $claim = $this->withHeaders($issued['headers'])
        ->postJson(route('api.work.claim', $team), workDrainClaimPayload(2))
        ->assertOk();

    foreach (['delivered', 'routed', 'failed'] as $state) {
        $this->withHeaders($issued['headers'])->postJson(route('api.work.transition', $team), [
            'item_id' => $completed->id,
            'fence' => $claim->json('items.0.fence'),
            'state' => $state,
        ])->assertOk()->assertJsonPath('item.state', $state);
    }

    $this->withHeaders($issued['headers'])->postJson(route('api.work.transition', $team), [
        'item_id' => $released->id,
        'fence' => $claim->json('items.1.fence'),
        'state' => 'released',
    ])->assertOk()
        ->assertJsonPath('item.state', 'queued')
        ->assertJsonMissingPath('item.lease_until');

    expect($completed->fresh()?->transitions()->pluck('to_state')->all())->toBe([
        WorkItemState::Claimed,
        WorkItemState::Delivered,
        WorkItemState::Routed,
        WorkItemState::Failed,
    ])->and($released->fresh()?->transitions()->pluck('to_state')->all())->toBe([
        WorkItemState::Claimed,
        WorkItemState::Queued,
    ])->and($released->fresh()?->attempts)->toBe(1)
        ->and($released->fresh()?->last_progress)->toBe('Test-created progress')
        ->and($released->fresh()?->claimant_spoke_id)->toBeNull();
});

test('illegal target and illegal order return validation errors without mutation or events', function (): void {
    $team = workDrainTeam();
    $item = workDrainItem(workDrainTask($team));
    $issued = workDrainCredential(capstanUser());
    $claim = $this->withHeaders($issued['headers'])
        ->postJson(route('api.work.claim', $team), workDrainClaimPayload())
        ->assertOk();
    $before = $item->fresh()?->getAttributes();

    $this->withHeaders($issued['headers'])->postJson(route('api.work.transition', $team), [
        'item_id' => $item->id,
        'fence' => $claim->json('items.0.fence'),
        'state' => 'acted',
    ])->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');

    $this->withHeaders($issued['headers'])->postJson(route('api.work.transition', $team), [
        'item_id' => $item->id,
        'fence' => $claim->json('items.0.fence'),
        'state' => 'routed',
    ])->assertUnprocessable()->assertJsonPath('error.code', 'illegal_transition');

    expect($item->fresh()?->getAttributes())->toBe($before)
        ->and($item->transitions()->count())->toBe(1);
});

test('a reclaimed item fences its first claimant while its second claimant succeeds', function (): void {
    config(['capstan.work.lease_seconds' => 60]);
    Date::setTestNow('2026-10-17 13:00:00');
    $team = workDrainTeam();
    $item = workDrainItem(workDrainTask($team));
    $first = workDrainCredential(capstanUser());
    $second = workDrainCredential(capstanUser());
    $firstClaim = $this->withHeaders($first['headers'])
        ->postJson(route('api.work.claim', $team), workDrainClaimPayload())
        ->assertOk();

    Date::setTestNow('2026-10-17 13:01:01');
    resolve(WorkItemStateService::class)->expireLease(
        $item,
        'system',
        'test-lease-expiry',
        CarbonImmutable::now(),
    );
    $secondClaim = $this->withHeaders($second['headers'])
        ->postJson(route('api.work.claim', $team), workDrainClaimPayload())
        ->assertOk();

    expect($firstClaim->json('items.0.fence'))->toBe(1)
        ->and($secondClaim->json('items.0.fence'))->toBe(2)
        ->and($item->fresh()?->attempts)->toBe(1);

    $this->withHeaders($first['headers'])->postJson(route('api.work.transition', $team), [
        'item_id' => $item->id,
        'fence' => 1,
        'state' => 'delivered',
    ])->assertConflict()->assertJsonPath('error.code', 'claim_lost');
    $this->withHeaders($first['headers'])->postJson(route('api.work.renew', $team), [
        'items' => [['item_id' => $item->id, 'fence' => 1]],
    ])->assertOk()->assertExactJson([
        'results' => [['item_id' => $item->id, 'status' => 'lost']],
    ]);

    $this->withHeaders($second['headers'])->postJson(route('api.work.transition', $team), [
        'item_id' => $item->id,
        'fence' => 2,
        'state' => 'delivered',
    ])->assertOk();
    $this->withHeaders($second['headers'])->postJson(route('api.work.renew', $team), [
        'items' => [['item_id' => $item->id, 'fence' => 2]],
    ])->assertOk()->assertJsonPath('results.0.status', 'renewed');
});

test('another credential cannot act with a guessed current fence', function (): void {
    $team = workDrainTeam();
    $item = workDrainItem(workDrainTask($team));
    $user = capstanUser();
    $owner = workDrainCredential($user);
    $otherSpoke = workDrainCredential($user);
    $claim = $this->withHeaders($owner['headers'])
        ->postJson(route('api.work.claim', $team), workDrainClaimPayload())
        ->assertOk();
    $fence = $claim->json('items.0.fence');

    $this->withHeaders($otherSpoke['headers'])->postJson(route('api.work.transition', $team), [
        'item_id' => $item->id,
        'fence' => $fence,
        'state' => 'delivered',
    ])->assertConflict()->assertJsonPath('error.code', 'claim_lost');
    $this->withHeaders($otherSpoke['headers'])->postJson(route('api.work.renew', $team), [
        'items' => [['item_id' => $item->id, 'fence' => $fence]],
    ])->assertOk()->assertJsonPath('results.0.status', 'lost');

    expect($item->fresh()?->state)->toBe(WorkItemState::Claimed)
        ->and($item->transitions()->count())->toBe(1);
});

test('a claimed item cancellation makes transition and renew lose without further mutation', function (): void {
    Date::setTestNow('2026-10-17 13:30:00');
    $team = workDrainTeam();
    $item = workDrainItem(workDrainTask($team));
    $issued = workDrainCredential(capstanUser());
    $claim = $this->withHeaders($issued['headers'])
        ->postJson(route('api.work.claim', $team), workDrainClaimPayload())
        ->assertOk();
    resolve(WorkItemStateService::class)->transition(
        $item,
        WorkItemState::Cancelled,
        'user',
        'test-canceller',
        CarbonImmutable::now()->addSecond(),
    );
    $cancelled = $item->fresh();
    $before = $cancelled?->getAttributes();
    $events = $cancelled?->transitions()->count();

    $this->withHeaders($issued['headers'])->postJson(route('api.work.transition', $team), [
        'item_id' => $item->id,
        'fence' => $claim->json('items.0.fence'),
        'state' => 'delivered',
    ])->assertConflict()->assertJsonPath('error.code', 'claim_lost');
    $this->withHeaders($issued['headers'])->postJson(route('api.work.renew', $team), [
        'items' => [['item_id' => $item->id, 'fence' => $claim->json('items.0.fence')]],
    ])->assertOk()->assertJsonPath('results.0.status', 'lost');

    expect($item->fresh()?->getAttributes())->toBe($before)
        ->and($item->transitions()->count())->toBe($events);
});

test('a claimed task cancellation makes transition and renew lose without item mutation', function (): void {
    Date::setTestNow('2026-10-17 13:40:00');
    $team = workDrainTeam();
    $task = workDrainTask($team);
    $item = workDrainItem($task);
    $issued = workDrainCredential(capstanUser());
    $claim = $this->withHeaders($issued['headers'])
        ->postJson(route('api.work.claim', $team), workDrainClaimPayload())
        ->assertOk();
    $task->forceFill(['cancelled_at' => CarbonImmutable::now()->addSecond()])->save();
    $before = $item->fresh()?->getAttributes();
    $events = $item->transitions()->count();

    $this->withHeaders($issued['headers'])->postJson(route('api.work.transition', $team), [
        'item_id' => $item->id,
        'fence' => $claim->json('items.0.fence'),
        'state' => 'delivered',
    ])->assertConflict()->assertJsonPath('error.code', 'claim_lost');
    $this->withHeaders($issued['headers'])->postJson(route('api.work.renew', $team), [
        'items' => [['item_id' => $item->id, 'fence' => $claim->json('items.0.fence')]],
    ])->assertOk()->assertJsonPath('results.0.status', 'lost');

    expect($item->fresh()?->getAttributes())->toBe($before)
        ->and($item->transitions()->count())->toBe($events);
});

test('mixed renew results preserve input order and commit every valid renewal', function (): void {
    config(['capstan.work.lease_seconds' => 60]);
    Date::setTestNow('2026-10-17 14:00:00');
    $team = workDrainTeam();
    $task = workDrainTask($team);
    $first = workDrainItem($task);
    $second = workDrainItem($task);
    $issued = workDrainCredential(capstanUser());
    $claim = $this->withHeaders($issued['headers'])
        ->postJson(route('api.work.claim', $team), workDrainClaimPayload(2))
        ->assertOk();
    $lostId = (string) Str::uuid7();

    Date::setTestNow('2026-10-17 14:00:30');
    $response = $this->withHeaders($issued['headers'])->postJson(route('api.work.renew', $team), [
        'items' => [
            ['item_id' => $second->id, 'fence' => $claim->json('items.1.fence')],
            ['item_id' => $lostId, 'fence' => 99],
            ['item_id' => $first->id, 'fence' => $claim->json('items.0.fence')],
        ],
    ])->assertOk();

    expect($response->json('results.*.item_id'))->toBe([$second->id, $lostId, $first->id])
        ->and($response->json('results.*.status'))->toBe(['renewed', 'lost', 'renewed'])
        ->and($response->json('results.1'))->toBe(['item_id' => $lostId, 'status' => 'lost'])
        ->and($first->fresh()?->lease_until?->toISOString())->toBe('2026-10-17T14:01:30.000000Z')
        ->and($second->fresh()?->lease_until?->toISOString())->toBe('2026-10-17T14:01:30.000000Z');
});

test('work off hides authenticated routes and other bound purposes cannot authorize them', function (): void {
    $team = workDrainTeam();
    $user = capstanUser();
    $work = workDrainCredential($user);
    config(['capstan.features.work' => false]);
    Feature::flushCache();

    foreach (['claim', 'transition', 'renew'] as $action) {
        $this->withHeaders($work['headers'])
            ->postJson(route("api.work.$action", $team), [])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    config(['capstan.features.work' => true]);
    Feature::flushCache();

    foreach ([CapstanCredentialDeclaration::ARTIFACT_INGEST, CapstanCredentialDeclaration::POSTMASTER_POLL] as $purpose) {
        $issued = capstanBoundBearer($user, $purpose);
        $this->withHeaders([
            'Authorization' => 'Bearer '.$issued['token'],
            CapstanCredentialDeclaration::ACTOR_HEADER => (string) $user->getKey(),
        ])->postJson(route('api.work.claim', $team), workDrainClaimPayload())
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }
});

test('work credentials remain bound to the presented active product actor', function (): void {
    $team = workDrainTeam();
    $item = workDrainItem(workDrainTask($team));
    $owner = capstanUser();
    $other = capstanUser();
    $issued = workDrainCredential($owner);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$issued['token'],
        CapstanCredentialDeclaration::ACTOR_HEADER => (string) $other->getKey(),
    ])->postJson(route('api.work.claim', $team), workDrainClaimPayload())
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');

    $owner->forceFill(['status' => 'inactive'])->save();
    $this->withHeaders($issued['headers'])
        ->postJson(route('api.work.claim', $team), workDrainClaimPayload())
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');

    expect($item->fresh()?->state)->toBe(WorkItemState::Queued)
        ->and(Spoke::query()->count())->toBe(0);
});

test('renew enforces its bounded distinct object list before state changes', function (array $items, string $error): void {
    $team = workDrainTeam();
    $item = workDrainItem(workDrainTask($team));
    $issued = workDrainCredential(capstanUser());
    $claim = $this->withHeaders($issued['headers'])
        ->postJson(route('api.work.claim', $team), workDrainClaimPayload())
        ->assertOk();
    $before = $item->fresh()?->lease_until;
    $items = array_map(
        fn (array $entry): array => [
            'item_id' => $entry['item_id'] === 'claimed' ? $item->id : $entry['item_id'],
            'fence' => $entry['fence'] === -1 ? $claim->json('items.0.fence') : $entry['fence'],
        ],
        $items,
    );

    $this->withHeaders($issued['headers'])->postJson(route('api.work.renew', $team), ['items' => $items])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonValidationErrors($error, 'error.errors');

    expect($item->fresh()?->lease_until?->equalTo($before))->toBeTrue();
})->with([
    'empty batch' => [[], 'items'],
    'duplicate item ids' => [[
        ['item_id' => 'claimed', 'fence' => -1],
        ['item_id' => 'claimed', 'fence' => -1],
    ], 'items.1.item_id'],
    'over cap batch' => [array_fill(0, 26, ['item_id' => 'claimed', 'fence' => -1]), 'items'],
]);

test('cross team item ids are opaque to transition and renew', function (): void {
    $rightTeam = workDrainTeam();
    $wrongTeam = workDrainTeam();
    $item = workDrainItem(workDrainTask($rightTeam));
    $issued = workDrainCredential(capstanUser());
    $claim = $this->withHeaders($issued['headers'])
        ->postJson(route('api.work.claim', $rightTeam), workDrainClaimPayload())
        ->assertOk();
    $fence = $claim->json('items.0.fence');

    $this->withHeaders($issued['headers'])->postJson(route('api.work.transition', $wrongTeam), [
        'item_id' => $item->id,
        'fence' => $fence,
        'state' => 'delivered',
    ])->assertConflict()->assertJsonPath('error.code', 'claim_lost');
    $this->withHeaders($issued['headers'])->postJson(route('api.work.renew', $wrongTeam), [
        'items' => [['item_id' => $item->id, 'fence' => $fence]],
    ])->assertOk()->assertExactJson([
        'results' => [['item_id' => $item->id, 'status' => 'lost']],
    ]);

    expect($item->fresh()?->state)->toBe(WorkItemState::Claimed)
        ->and($item->transitions()->count())->toBe(1);
});

test('non positive lease configuration is safely normalized', function (): void {
    config(['capstan.work.lease_seconds' => 0]);
    Date::setTestNow('2026-10-17 15:00:00');
    $team = workDrainTeam();
    workDrainItem(workDrainTask($team));
    $issued = workDrainCredential(capstanUser());

    $this->withHeaders($issued['headers'])
        ->postJson(route('api.work.claim', $team), workDrainClaimPayload())
        ->assertOk()
        ->assertJsonPath('items.0.lease_until', '2026-10-17T15:00:01.000000Z');
});
