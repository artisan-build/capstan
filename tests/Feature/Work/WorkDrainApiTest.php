<?php

use App\Auth\CapstanCredentialDeclaration;
use App\Enums\PageLayout;
use App\Enums\WorkItemState;
use App\Enums\WorkMilestoneKind;
use App\Models\Inbox;
use App\Models\MachineTeamWorkSetting;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\Spoke;
use App\Models\Team;
use App\Models\WorkDirective;
use App\Models\WorkEscalation;
use App\Models\WorkItem;
use App\Models\WorkOrchestratorSeat;
use App\Models\WorkTask;
use App\Work\WorkDirectiveService;
use App\Work\WorkItemStateService;
use App\Work\WorkNotifier;
use App\Work\WorkOrchestrationService;
use App\Work\WorkSeatService;
use App\Work\WorkSweepService;
use App\Work\WorkWorkerLinks;
use App\Work\WorkWorkerService;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    config([
        'capstan.work.lease_seconds' => 300,
    ]);
});

/** @param array<string, mixed> $attributes */
function workDrainTeam(array $attributes = []): Team
{
    $suffix = Str::lower(Str::random(8));

    return Team::query()->create(array_replace([
        'name' => 'Work drain team '.$suffix,
        'slug' => 'work-drain-'.$suffix,
        'is_default' => false,
        'pool_work' => true,
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

/** @return array{token: string, credential: Credential, spoke: Spoke, headers: array<string, string>} */
function workDrainCredential(User $user): array
{
    $issued = capstanBoundBearer($user, CapstanCredentialDeclaration::MACHINE);
    $spoke = Spoke::query()->create([
        'actor_id' => (string) $user->getKey(),
        'credential_id' => $issued['credential']->id,
        'last_polled_at' => now()->addDay(),
    ]);
    foreach (Team::query()->get() as $team) {
        MachineTeamWorkSetting::query()->create([
            'spoke_id' => $spoke->id,
            'team_id' => $team->id,
            'enabled' => true,
            'roles' => ['builder', 'human', 'orchestrator', 'security'],
            'capabilities' => ['docker', 'git', 'php'],
            'role_providers' => [],
            'concurrency_ceiling' => 25,
            'updated_by_actor' => (string) $user->getKey(),
        ]);
    }

    return [
        ...$issued,
        'spoke' => $spoke,
        'headers' => [
            'Authorization' => 'Bearer '.$issued['token'],
            CapstanCredentialDeclaration::ACTOR_HEADER => (string) $user->getKey(),
        ],
    ];
}

/**
 * @param  list<string>  $roles
 * @param  list<string>  $capabilities
 * @return array{capacity: int, roles: list<string>, capabilities: list<string>, role_providers: array<string, string>, current_claimed_item_count: int}
 */
function workDrainClaimPayload(int $capacity = 1, array $roles = ['builder'], array $capabilities = []): array
{
    return [...compact('capacity', 'roles', 'capabilities'), 'role_providers' => [], 'current_claimed_item_count' => 0];
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
        ->and(array_keys($response->json('items.0')))->toBe(['id', 'task_id', 'role', 'fence', 'lease_until', 'links', 'launch_nonce'])
        ->and(json_encode($response->json(), JSON_THROW_ON_ERROR))->not->toContain('null')
        ->and(WorkItem::query()->where('state', WorkItemState::Claimed)->count())->toBe(25)
        ->and(WorkItem::query()->where('state', WorkItemState::Queued)->count())->toBe(2)
        ->and($response->json('items.0.lease_until'))->toBe('2026-10-17T11:05:00.000000Z');

    $this->withHeaders($issued['headers'])
        ->postJson(route('api.work.claim', $team), workDrainClaimPayload(0))
        ->assertOk()
        ->assertExactJson(['items' => [], 'directives' => []]);

    expect(WorkItem::query()->where('state', WorkItemState::Queued)->count())->toBe(2);
});

test('claim and renewed records carry fresh signed links through the current lease grace', function (): void {
    Date::setTestNow('2026-10-17 10:00:00');
    config(['capstan.work.link_grace_seconds' => 60]);
    $team = workDrainTeam();
    $item = workDrainItem(workDrainTask($team, ['timebox_minutes' => 6]));
    $issued = workDrainCredential(capstanUser());
    $claim = $this->withHeaders($issued['headers'])->postJson(route('api.work.claim', $team), workDrainClaimPayload())->assertOk();
    $links = $claim->json('items.0.links');
    expect(array_keys($links))->toBe(['brief', 'milestone', 'steers', 'evidence']);
    Date::setTestNow(now()->addMinutes(2));
    $renew = $this->withHeaders($issued['headers'])->postJson(route('api.work.renew', $team), [
        'items' => [['item_id' => $item->id, 'fence' => 1]],
    ])->assertOk();
    foreach ($renew->json('results.0.item.links') as $action => $url) {
        expect($url)->not->toBe($links[$action]);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        expect((int) $query['expires'])->toBe(CarbonImmutable::parse('2026-10-17 10:08:00')->timestamp);
    }
    $this->getJson($renew->json('results.0.item.links.brief'))->assertOk();
});

test('nonce receipt records the exact served brief and model while rejecting another signed nonce', function (): void {
    Date::setTestNow('2026-10-23 10:00:00');
    $team = workDrainTeam();
    $task = workDrainTask($team);
    $revision = $task->briefRevisions()->create(['sequence' => 1, 'content' => 'Receipt brief', 'author_actor' => 'creator']);
    $item = workDrainItem($task);
    $issued = workDrainCredential(capstanUser());
    $claim = $this->withHeaders($issued['headers'])->postJson(route('api.work.claim', $team), workDrainClaimPayload())->assertOk();
    $nonce = $claim->json('items.0.launch_nonce');

    $this->withHeaders($issued['headers'])->postJson(route('api.work.transition', $team), [
        'item_id' => $item->id, 'fence' => 1, 'state' => 'delivered',
        'launch_nonce' => $nonce, 'session_ref' => 'solo:receipt-session',
    ])->assertOk();

    $wrong = URL::temporarySignedRoute('api.work.worker.brief', now()->addMinutes(5), [
        'item' => $item->id, 'fence' => 1, 'nonce' => (string) Str::uuid7(),
    ]);
    $this->getJson($wrong)->assertConflict()->assertSeeText('launch nonce mismatch');
    expect($item->fresh()->first_fetched_at)->toBeNull();

    $this->getJson($claim->json('items.0.links.brief').'&model=gpt-5.6-sol')->assertOk()
        ->assertJsonPath('revision.id', $revision->id)
        ->assertJsonPath('revision.content', 'Receipt brief');
    $receipt = $item->fresh();
    expect($receipt->first_fetched_at?->toJSON())->toBe(now()->toJSON())
        ->and($receipt->agent_model)->toBe('gpt-5.6-sol')
        ->and($receipt->fetched_brief_revision_id)->toBe($revision->id)
        ->and($receipt->handed_off_session_ref)->toBe('solo:receipt-session');
});

test('a pre-handoff Page brief receipt survives delivery and prevents receipt repair', function (): void {
    Date::setTestNow('2026-10-23 10:30:00');
    config(['capstan.work.receipt_deadline_seconds' => 60]);
    $team = workDrainTeam();
    $user = capstanUser();
    $page = Page::query()->create([
        'owner_user_id' => (string) $user->id,
        'title' => 'Pre-handoff receipt Page',
        'refresh_recipe' => 'Test-created receipt recipe',
        'layout' => PageLayout::Brief,
    ]);
    $pageVersion = PageVersion::query()->create([
        'page_id' => $page->id,
        'version_number' => 1,
        'content_type' => 'text/html',
        'size_bytes' => 25,
        'content_hash' => hash('sha256', '<p>Receipt Page brief</p>'),
        'storage_key' => 'pages/'.$page->id.'/v1.html',
        'publisher_user_id' => (string) $user->id,
        'publisher_source' => 'test',
        'publisher_tool' => 'test',
        'refresh_recipe' => 'Test-created receipt recipe',
        'layout' => PageLayout::Brief,
    ]);
    $page->update(['latest_version_id' => $pageVersion->id, 'version_count' => 1]);
    $task = workDrainTask($team, [
        'brief_page_id' => $page->id,
        'brief_page_version_id' => $pageVersion->id,
    ]);
    $revision = $task->briefRevisions()->create([
        'sequence' => 1,
        'content' => 'Pre-handoff receipt brief',
        'author_actor' => 'creator',
    ]);
    $item = workDrainItem($task);
    $issued = workDrainCredential($user);
    $claim = $this->withHeaders($issued['headers'])
        ->postJson(route('api.work.claim', $team), workDrainClaimPayload())
        ->assertOk();
    $nonce = $claim->json('items.0.launch_nonce');

    expect($item->fresh()->state)->toBe(WorkItemState::Claimed);
    $fetchedAt = now();
    $this->getJson($claim->json('items.0.links.brief').'&model=gpt-5.6-sol')
        ->assertOk()
        ->assertJsonPath('revision.id', $revision->id)
        ->assertJsonPath('task.brief_page_version_id', $pageVersion->id);

    Date::setTestNow(now()->addSeconds(30));
    $handedOffAt = now();
    $this->withHeaders($issued['headers'])->postJson(route('api.work.transition', $team), [
        'item_id' => $item->id,
        'fence' => 1,
        'state' => 'delivered',
        'launch_nonce' => $nonce,
        'session_ref' => 'solo:pre-handoff-receipt',
    ])->assertOk();

    $receipt = $item->fresh();
    expect($receipt->first_fetched_at?->toJSON())->toBe($fetchedAt->toJSON())
        ->and($receipt->agent_model)->toBe('gpt-5.6-sol')
        ->and($receipt->fetched_brief_revision_id)->toBe($revision->id)
        ->and($receipt->fetched_page_version_id)->toBe($pageVersion->id)
        ->and($receipt->handed_off_at?->toJSON())->toBe($handedOffAt->toJSON())
        ->and($receipt->handed_off_session_ref)->toBe('solo:pre-handoff-receipt')
        ->and($receipt->launch_nonce)->toBe($nonce);

    Date::setTestNow(now()->addSeconds(61));
    $this->withHeaders($issued['headers'])->postJson(route('api.work.claim', $team), [
        ...workDrainClaimPayload(0),
        'orchestrator_capacity' => 0,
        'held_items' => [['item_id' => $item->id, 'fence' => 1]],
    ])->assertOk()->assertExactJson(['items' => [], 'directives' => []]);
});

test('missed receipts replay one pending directive advance in order and escalate exactly once', function (): void {
    Date::setTestNow('2026-10-23 11:00:00');
    config(['capstan.work.receipt_deadline_seconds' => 180]);
    $team = workDrainTeam();
    $task = workDrainTask($team);
    $item = workDrainItem($task);
    $issued = workDrainCredential(capstanUser());
    $claim = $this->withHeaders($issued['headers'])->postJson(route('api.work.claim', $team), workDrainClaimPayload())->assertOk();
    $nonce = $claim->json('items.0.launch_nonce');
    $this->withHeaders($issued['headers'])->postJson(route('api.work.transition', $team), [
        'item_id' => $item->id, 'fence' => 1, 'state' => 'delivered',
        'launch_nonce' => $nonce, 'session_ref' => 'solo:missed-receipt',
    ])->assertOk();

    $poll = (fn (array $held) => $this->withHeaders($issued['headers'])->postJson(route('api.work.claim', $team), [
        ...workDrainClaimPayload(0),
        'orchestrator_capacity' => 0,
        'held_items' => [$held],
    ])->assertOk());
    $held = ['item_id' => $item->id, 'fence' => 1];

    Date::setTestNow(now()->addSeconds(181));
    $first = $poll($held)->assertJsonPath('directives.0.kind', 'send_enter');
    $firstId = $first->json('directives.0.id');
    $poll($held)->assertJsonPath('directives.0.id', $firstId);
    expect(WorkDirective::query()->count())->toBe(1);

    expect($poll([...$held, 'directive_id' => $firstId, 'directive_outcome' => 'failed'])->json('directives'))->toBe([]);
    Date::setTestNow(now()->addSeconds(181));
    $second = $poll($held)->assertJsonPath('directives.0.kind', 'resend_pointer');
    $secondId = $second->json('directives.0.id');
    expect($poll([...$held, 'directive_id' => $secondId, 'directive_outcome' => 'failed'])->json('directives'))->toBe([]);

    Date::setTestNow(now()->addSeconds(181));
    $third = $poll($held)->assertJsonPath('directives.0.kind', 'respawn');
    $thirdId = $third->json('directives.0.id');
    $firstRespawnNonce = $third->json('directives.0.payload.launch_nonce');
    expect($firstRespawnNonce)->toBe($item->fresh()->launch_nonce)
        ->and($third->json('directives.0.links.brief'))->toBeString();
    expect($poll([...$held, 'directive_id' => $thirdId, 'directive_outcome' => 'failed'])->json('directives'))->toBe([]);

    Date::setTestNow(now()->addSeconds(181));
    $fourth = $poll($held)->assertJsonPath('directives.0.kind', 'respawn');
    $fourthId = $fourth->json('directives.0.id');
    $secondRespawnNonce = $fourth->json('directives.0.payload.launch_nonce');
    expect($secondRespawnNonce)->not->toBe($firstRespawnNonce)
        ->and($secondRespawnNonce)->toBe($item->fresh()->launch_nonce)
        ->and(WorkEscalation::query()->count())->toBe(0);
    expect($poll([...$held, 'directive_id' => $fourthId, 'directive_outcome' => 'failed'])->json('directives'))->toBe([])
        ->and(WorkEscalation::query()->count())->toBe(0);

    Date::setTestNow(now()->addSeconds(179));
    expect($poll($held)->json('directives'))->toBe([])
        ->and(WorkEscalation::query()->count())->toBe(0);
    Date::setTestNow(now()->addSecond());
    expect($poll($held)->json('directives'))->toBe([]);
    expect($poll($held)->json('directives'))->toBe([]);
    expect(WorkDirective::query()->pluck('kind')->all())->toBe(['send_enter', 'resend_pointer', 'respawn', 'respawn'])
        ->and(WorkEscalation::query()->where('request_key', 'directive-exhausted:'.$item->id.':1:receipt')->count())->toBe(1);
});

test('respawn nonce rotation revokes every old signed capability while fresh links succeed', function (): void {
    Date::setTestNow('2026-10-23 11:30:00');
    Storage::fake();
    config(['capstan.work.orchestration.receipt_deadline_seconds' => 60]);
    $team = workDrainTeam();
    $issued = workDrainCredential(capstanUser());
    $task = workDrainTask($team, ['user_actor_address' => $issued['spoke']->actor_id]);
    $task->briefRevisions()->create(['sequence' => 1, 'content' => 'Rotated brief', 'author_actor' => 'creator']);
    $unread = $task->steers()->create(['text' => 'Unread before rotation', 'author_actor' => 'creator']);
    resolve(WorkOrchestrationService::class)->event($task, 'nonce-rotation', 'test_event');
    $item = $task->items()->where('role', WorkItem::ROLE_ORCHESTRATOR)->sole();
    $claim = $this->withHeaders($issued['headers'])->postJson(route('api.work.claim', $team), [
        ...workDrainClaimPayload(0, ['orchestrator']),
        'orchestrator_capacity' => 1,
        'held_items' => [],
    ])->assertOk()->assertJsonPath('items.0.id', $item->id);
    $oldLinks = $claim->json('items.0.links');
    $this->withHeaders($issued['headers'])->postJson(route('api.work.transition', $team), [
        'item_id' => $item->id,
        'fence' => 1,
        'state' => 'delivered',
        'launch_nonce' => $claim->json('items.0.launch_nonce'),
        'session_ref' => 'solo:replaced-session',
    ])->assertOk();

    $held = ['item_id' => $item->id, 'fence' => 1];
    $poll = fn (array $reported) => $this->withHeaders($issued['headers'])->postJson(route('api.work.claim', $team), [
        ...workDrainClaimPayload(0, ['orchestrator']),
        'orchestrator_capacity' => 0,
        'held_items' => [$reported],
    ])->assertOk();
    Date::setTestNow(now()->addSeconds(61));
    $first = $poll($held)->assertJsonPath('directives.0.kind', 'send_enter');
    $poll([...$held, 'directive_id' => $first->json('directives.0.id'), 'directive_outcome' => 'failed']);
    Date::setTestNow(now()->addSeconds(61));
    $second = $poll($held)->assertJsonPath('directives.0.kind', 'resend_pointer');
    $poll([...$held, 'directive_id' => $second->json('directives.0.id'), 'directive_outcome' => 'failed']);
    Date::setTestNow(now()->addSeconds(61));
    $respawn = $poll($held)->assertJsonPath('directives.0.kind', 'respawn');
    $freshLinks = $respawn->json('directives.0.links');

    $this->getJson($oldLinks['brief'])->assertConflict();
    $this->postJson($oldLinks['milestone'], ['kind' => 'note', 'payload' => ['summary' => 'Must not persist']])->assertConflict();
    $this->getJson($oldLinks['steers'])->assertConflict();
    $this->post($oldLinks['evidence'], ['file' => UploadedFile::fake()->createWithContent('stale.txt', "stale\n")])->assertConflict();
    $this->getJson($oldLinks['orchestration'])->assertForbidden();
    $this->postJson($oldLinks['orchestrate'], [
        'action' => 'set_priority',
        'input' => ['priority' => 'P3'],
        'idempotency_key' => 'nonce-rotation-priority',
    ])->assertForbidden();

    expect($item->fresh()->first_fetched_at)->toBeNull()
        ->and($item->milestones()->count())->toBe(0)
        ->and($item->evidence()->count())->toBe(0)
        ->and($unread->fresh()->read_at)->toBeNull()
        ->and($task->fresh()->priority->value)->toBe('P2');

    $this->getJson($freshLinks['brief'])->assertOk();
    $this->postJson($freshLinks['milestone'], ['kind' => 'note', 'payload' => ['summary' => 'Fresh session']])->assertCreated();
    $this->getJson($freshLinks['steers'])->assertOk()->assertJsonPath('steers.0.id', $unread->id);
    $this->post($freshLinks['evidence'], ['file' => UploadedFile::fake()->createWithContent('fresh.txt', "fresh\n")])->assertCreated();
    $this->getJson($freshLinks['orchestration'])->assertOk();
    $this->postJson($freshLinks['orchestrate'], [
        'action' => 'set_priority',
        'input' => ['priority' => 'P3'],
        'idempotency_key' => 'nonce-rotation-priority',
    ])->assertOk()->assertJsonPath('outcome', 'accepted');

    expect($item->fresh()->first_fetched_at)->not->toBeNull()
        ->and($item->milestones()->count())->toBe(1)
        ->and($item->evidence()->count())->toBe(1)
        ->and($unread->fresh()->read_at)->not->toBeNull()
        ->and($task->fresh()->priority->value)->toBe('P3');
});

test('orchestrator capacity remains available when leaf capacity is full', function (): void {
    Date::setTestNow('2026-10-23 12:00:00');
    $team = workDrainTeam();
    $user = capstanUser();
    $issued = workDrainCredential($user);
    MachineTeamWorkSetting::query()->where('spoke_id', $issued['spoke']->id)->where('team_id', $team->id)
        ->update(['concurrency_ceiling' => 1]);
    $leaf = workDrainItem(workDrainTask($team), ['role' => 'builder']);
    $queuedLeaf = workDrainItem(workDrainTask($team), ['role' => 'builder']);
    $orchestratorTask = workDrainTask($team);
    resolve(WorkOrchestrationService::class)->event($orchestratorTask, 'capacity-wake', 'test_event');
    $orchestrator = $orchestratorTask->items()->where('role', WorkItem::ROLE_ORCHESTRATOR)->sole();

    $this->withHeaders($issued['headers'])->postJson(route('api.work.claim', $team), workDrainClaimPayload(1, ['builder', 'orchestrator']))
        ->assertOk()->assertJsonPath('items.0.id', $leaf->id);
    $response = $this->withHeaders($issued['headers'])->postJson(route('api.work.claim', $team), [
        ...workDrainClaimPayload(0, ['builder', 'orchestrator']),
        'orchestrator_capacity' => 1,
        'held_items' => [],
    ])->assertOk();

    expect($response->json('items.*.id'))->toBe([$orchestrator->id])
        ->and($queuedLeaf->fresh()->state)->toBe(WorkItemState::Queued)
        ->and($orchestrator->fresh()->state)->toBe(WorkItemState::Claimed);
});

test('omitted orchestrator capacity shares one legacy ceiling in either claim order', function (): void {
    Date::setTestNow('2026-10-23 12:30:00');

    $orchestratorFirstTeam = workDrainTeam();
    $orchestratorFirst = workDrainCredential(capstanUser());
    MachineTeamWorkSetting::query()->where('spoke_id', $orchestratorFirst['spoke']->id)->where('team_id', $orchestratorFirstTeam->id)
        ->update(['concurrency_ceiling' => 1]);
    $orchestratorTask = workDrainTask($orchestratorFirstTeam);
    resolve(WorkOrchestrationService::class)->event($orchestratorTask, 'legacy-orchestrator-first', 'test_event');
    $orchestrator = $orchestratorTask->items()->where('role', WorkItem::ROLE_ORCHESTRATOR)->sole();
    $queuedLeaf = workDrainItem(workDrainTask($orchestratorFirstTeam));
    $this->withHeaders($orchestratorFirst['headers'])
        ->postJson(route('api.work.claim', $orchestratorFirstTeam), workDrainClaimPayload(1, ['orchestrator']))
        ->assertOk()->assertJsonPath('items.0.id', $orchestrator->id);
    $this->withHeaders($orchestratorFirst['headers'])
        ->postJson(route('api.work.claim', $orchestratorFirstTeam), workDrainClaimPayload(1, ['builder']))
        ->assertOk()->assertJsonCount(0, 'items');
    expect($queuedLeaf->fresh()->state)->toBe(WorkItemState::Queued);

    $leafFirstTeam = workDrainTeam();
    $leafFirst = workDrainCredential(capstanUser());
    MachineTeamWorkSetting::query()->where('spoke_id', $leafFirst['spoke']->id)->where('team_id', $leafFirstTeam->id)
        ->update(['concurrency_ceiling' => 1]);
    $leaf = workDrainItem(workDrainTask($leafFirstTeam));
    $leafOrchestratorTask = workDrainTask($leafFirstTeam);
    resolve(WorkOrchestrationService::class)->event($leafOrchestratorTask, 'legacy-leaf-first', 'test_event');
    $queuedOrchestrator = $leafOrchestratorTask->items()->where('role', WorkItem::ROLE_ORCHESTRATOR)->sole();
    $this->withHeaders($leafFirst['headers'])
        ->postJson(route('api.work.claim', $leafFirstTeam), workDrainClaimPayload(1, ['builder']))
        ->assertOk()->assertJsonPath('items.0.id', $leaf->id);
    $this->withHeaders($leafFirst['headers'])
        ->postJson(route('api.work.claim', $leafFirstTeam), workDrainClaimPayload(1, ['orchestrator']))
        ->assertOk()->assertJsonCount(0, 'items');
    expect($queuedOrchestrator->fresh()->state)->toBe(WorkItemState::Queued);
});

test('receipt and stall repairs coexist on one item fence and stall escalation is singular', function (): void {
    Date::setTestNow('2026-10-23 12:45:00');
    config(['capstan.work.receipt_deadline_seconds' => 60]);
    $team = workDrainTeam();
    $task = workDrainTask($team);
    $task->briefRevisions()->create(['sequence' => 1, 'content' => 'Lifecycle brief', 'author_actor' => 'creator']);
    $item = workDrainItem($task);
    $issued = workDrainCredential(capstanUser());
    $claim = $this->withHeaders($issued['headers'])->postJson(route('api.work.claim', $team), workDrainClaimPayload())->assertOk();
    $this->withHeaders($issued['headers'])->postJson(route('api.work.transition', $team), [
        'item_id' => $item->id,
        'fence' => 1,
        'state' => 'delivered',
        'launch_nonce' => $claim->json('items.0.launch_nonce'),
        'session_ref' => 'solo:receipt-then-stall',
    ])->assertOk();
    $held = ['item_id' => $item->id, 'fence' => 1];
    $poll = fn (array $reported) => $this->withHeaders($issued['headers'])->postJson(route('api.work.claim', $team), [
        ...workDrainClaimPayload(0),
        'orchestrator_capacity' => 0,
        'held_items' => [$reported],
    ])->assertOk();

    Date::setTestNow(now()->addSeconds(61));
    $receipt = $poll($held)->assertJsonPath('directives.0.reason', 'receipt')->assertJsonPath('directives.0.sequence', 1);
    $this->getJson($claim->json('items.0.links.brief'))->assertOk();
    expect(WorkDirective::query()->findOrFail($receipt->json('directives.0.id'))->status)->toBe('succeeded');
    $this->withHeaders($issued['headers'])->postJson(route('api.work.transition', $team), [
        'item_id' => $item->id,
        'fence' => 1,
        'state' => 'routed',
    ])->assertOk();
    $item->update(['stalled_at' => now()->subSeconds(61)]);

    $kinds = [];
    foreach (['send_enter', 'resend_pointer', 'respawn', 'respawn'] as $expectedKind) {
        $directive = $poll($held)
            ->assertJsonPath('directives.0.reason', 'stall')
            ->assertJsonPath('directives.0.kind', $expectedKind);
        $id = $directive->json('directives.0.id');
        $sequence = $directive->json('directives.0.sequence');
        $poll($held)->assertJsonPath('directives.0.id', $id);
        $kinds[] = $expectedKind;
        expect($sequence)->toBe(count($kinds));
        $poll([...$held, 'directive_id' => $id, 'directive_outcome' => 'failed'])->assertJsonCount(0, 'directives');
        Date::setTestNow(now()->addSeconds(61));
    }

    $poll($held)->assertJsonCount(0, 'directives');
    $poll($held)->assertJsonCount(0, 'directives');
    expect(WorkDirective::query()->where('work_item_id', $item->id)->where('fence', 1)->where('reason', 'stall')->pluck('kind')->all())->toBe($kinds)
        ->and(WorkEscalation::query()->where('request_key', 'like', 'directive-exhausted:'.$item->id.':1:stall:%')->count())->toBe(1);
});

test('current-holder progress ends one stall episode and a later stall starts a complete new ladder', function (): void {
    Date::setTestNow('2026-10-23 14:00:00');
    config(['capstan.work.receipt_deadline_seconds' => 60]);
    $team = workDrainTeam();
    $task = workDrainTask($team, ['timebox_minutes' => 1]);
    $task->briefRevisions()->create(['sequence' => 1, 'content' => 'Recovery brief', 'author_actor' => 'creator']);
    $item = workDrainItem($task);
    $issued = workDrainCredential(capstanUser());
    $claim = $this->withHeaders($issued['headers'])
        ->postJson(route('api.work.claim', $team), workDrainClaimPayload())
        ->assertOk();
    $this->getJson($claim->json('items.0.links.brief'))->assertOk();
    foreach (['delivered', 'routed'] as $state) {
        $this->withHeaders($issued['headers'])->postJson(route('api.work.transition', $team), [
            'item_id' => $item->id,
            'fence' => 1,
            'state' => $state,
        ])->assertOk();
    }

    $held = ['item_id' => $item->id, 'fence' => 1];
    $poll = fn (array $reported) => $this->withHeaders($issued['headers'])->postJson(route('api.work.claim', $team), [
        ...workDrainClaimPayload(0),
        'orchestrator_capacity' => 0,
        'held_items' => [$reported],
    ])->assertOk();
    $item->update(['stalled_at' => now()->subSeconds(61)]);
    $old = $poll($held)
        ->assertJsonPath('directives.0.reason', 'stall')
        ->assertJsonPath('directives.0.kind', 'send_enter');
    $oldId = $old->json('directives.0.id');

    $this->postJson($claim->json('items.0.links.milestone'), [
        'kind' => 'note',
        'payload' => ['summary' => 'Recovered and progressing'],
    ])->assertCreated();

    $recovered = WorkDirective::query()->findOrFail($oldId);
    expect($item->fresh()->stalled_at)->toBeNull()
        ->and($item->fresh()->milestones()->count())->toBe(1)
        ->and($recovered->status)->toBe('succeeded')
        ->and($recovered->outcome)->toBe(['result' => 'resolved_by_progress'])
        ->and($recovered->outcome)->not->toHaveKey('receipt')
        ->and($recovered->completed_at?->toJSON())->toBe(now()->toJSON());

    Date::setTestNow(now()->addSeconds(61));
    $poll($held)->assertJsonCount(0, 'directives');
    $poll($held)->assertJsonCount(0, 'directives');
    expect(WorkEscalation::query()->where('request_key', 'like', 'directive-exhausted:'.$item->id.':1:stall:%')->count())->toBe(0);

    resolve(WorkSweepService::class)->liveness(CarbonImmutable::now());
    expect($item->fresh()->stalled_at?->toJSON())->toBe(now()->toJSON());
    Date::setTestNow(now()->addSeconds(61));

    $newIds = [];
    foreach (['send_enter', 'resend_pointer', 'respawn', 'respawn'] as $kind) {
        $directive = $poll($held)
            ->assertJsonPath('directives.0.reason', 'stall')
            ->assertJsonPath('directives.0.kind', $kind);
        $newIds[] = $directive->json('directives.0.id');
        $poll([
            ...$held,
            'directive_id' => $directive->json('directives.0.id'),
            'directive_outcome' => 'failed',
        ])->assertJsonCount(0, 'directives');
        Date::setTestNow(now()->addSeconds(61));
    }
    $poll($held)->assertJsonCount(0, 'directives');

    expect($newIds)->not->toContain($oldId)
        ->and(WorkDirective::query()->where('work_item_id', $item->id)->where('reason', 'stall')->orderBy('sequence')->pluck('sequence')->all())->toBe([1, 2, 3, 4, 5])
        ->and(WorkDirective::query()->findOrFail($oldId)->outcome)->toBe(['result' => 'resolved_by_progress'])
        ->and(WorkEscalation::query()->where('request_key', 'like', 'directive-exhausted:'.$item->id.':1:stall:%')->count())->toBe(1);
});

test('renewed HTTP capabilities survive a swept stall and later repair links remain usable', function (): void {
    Date::setTestNow('2026-10-23 14:30:00');
    expect(config('capstan.work.receipt_deadline_seconds'))->toBe(180)
        ->and(config('capstan.work.link_grace_seconds'))->toBe(60);
    $team = workDrainTeam();
    $task = workDrainTask($team, ['timebox_minutes' => 1]);
    $task->briefRevisions()->create(['sequence' => 1, 'content' => 'Leased recovery brief', 'author_actor' => 'creator']);
    $item = workDrainItem($task);
    $issued = workDrainCredential(capstanUser());
    $claim = $this->withHeaders($issued['headers'])
        ->postJson(route('api.work.claim', $team), workDrainClaimPayload())
        ->assertOk();
    foreach (['delivered', 'routed'] as $state) {
        $this->withHeaders($issued['headers'])->postJson(route('api.work.transition', $team), [
            'item_id' => $item->id,
            'fence' => 1,
            'state' => $state,
        ])->assertOk();
    }
    $renew = fn () => $this->withHeaders($issued['headers'])->postJson(route('api.work.renew', $team), [
        'items' => [['item_id' => $item->id, 'fence' => 1]],
    ])->assertOk();
    $poll = fn (array $reported) => $this->withHeaders($issued['headers'])->postJson(route('api.work.claim', $team), [
        ...workDrainClaimPayload(0),
        'orchestrator_capacity' => 0,
        'held_items' => [$reported],
    ])->assertOk();
    $held = ['item_id' => $item->id, 'fence' => 1];

    Date::setTestNow(now()->addSeconds(61));
    resolve(WorkSweepService::class)->liveness(CarbonImmutable::now());
    $firstEpisode = $item->fresh()->stalled_at;
    expect($firstEpisode)->not->toBeNull();

    Date::setTestNow(now()->addSeconds(59));
    $renew();
    Date::setTestNow(now()->addMinutes(2));
    $fresh = $renew();
    Date::setTestNow($firstEpisode->addSeconds(181));
    $first = $poll($held)
        ->assertJsonPath('directives.0.reason', 'stall')
        ->assertJsonPath('directives.0.kind', 'send_enter');
    $this->postJson($fresh->json('results.0.item.links.milestone'), [
        'kind' => 'note',
        'payload' => ['summary' => 'Recovered through a renewed capability'],
    ])->assertCreated();
    expect(WorkDirective::query()->findOrFail($first->json('directives.0.id'))->outcome)
        ->toBe(['result' => 'resolved_by_progress'])
        ->and($item->fresh()->stalled_at)->toBeNull();

    Date::setTestNow(now()->addSeconds(61));
    resolve(WorkSweepService::class)->liveness(CarbonImmutable::now());
    $secondEpisode = $item->fresh()->stalled_at;
    expect($secondEpisode)->not->toBeNull();
    Date::setTestNow(now()->addMinutes(2));
    $renew();
    Date::setTestNow($secondEpisode->addSeconds(181));
    $secondFirst = $poll($held)->assertJsonPath('directives.0.kind', 'send_enter');
    $poll([
        ...$held,
        'directive_id' => $secondFirst->json('directives.0.id'),
        'directive_outcome' => 'failed',
    ])->assertJsonCount(0, 'directives');
    Date::setTestNow(now()->addMinutes(2));
    $renew();
    Date::setTestNow(now()->addSeconds(61));
    $later = $poll($held)
        ->assertJsonPath('directives.0.kind', 'resend_pointer')
        ->assertJsonPath('directives.0.reason', 'stall');
    $this->getJson($later->json('directives.0.links.brief'))->assertOk();
    expect(WorkDirective::query()->findOrFail($later->json('directives.0.id'))->outcome)
        ->toBe(['result' => 'resolved_by_fetch']);
});

test('each exhausted stall episode creates one owner-visible escalation', function (): void {
    Date::setTestNow('2026-10-23 15:00:00');
    config(['capstan.work.receipt_deadline_seconds' => 60]);
    $team = workDrainTeam();
    $task = workDrainTask($team, ['timebox_minutes' => 1]);
    $issued = workDrainCredential(capstanUser());
    $item = workDrainItem($task, [
        'state' => 'routed',
        'fence' => 1,
        'claimant_actor' => $issued['spoke']->actor_id,
        'claimant_spoke_id' => $issued['spoke']->id,
        'claimed_at' => now()->subMinutes(2),
        'lease_until' => now()->addHour(),
        'first_fetched_at' => now(),
        'launch_nonce' => (string) Str::uuid7(),
    ]);
    $notifier = Mockery::mock(WorkNotifier::class);
    $notifier->shouldReceive('stalled')->twice();
    $notifier->shouldReceive('escalated')->twice()->withArgs(
        fn (WorkEscalation $escalation): bool => $escalation->notified_at !== null,
    );
    app()->instance(WorkNotifier::class, $notifier);
    $held = ['item_id' => $item->id, 'fence' => 1];
    $poll = fn (array $reported) => $this->withHeaders($issued['headers'])->postJson(route('api.work.claim', $team), [
        ...workDrainClaimPayload(0),
        'orchestrator_capacity' => 0,
        'held_items' => [$reported],
    ])->assertOk();
    $exhaust = function () use ($held, $poll): void {
        foreach (['send_enter', 'resend_pointer', 'respawn', 'respawn'] as $index => $kind) {
            Date::setTestNow(now()->addSeconds(61));
            $directive = $poll($held)
                ->assertJsonPath('directives.0.kind', $kind)
                ->assertJsonPath('directives.0.reason', 'stall');
            $reported = [
                ...$held,
                'directive_id' => $directive->json('directives.0.id'),
                'directive_outcome' => $index < 2 ? 'failed' : 'succeeded',
            ];
            if ($kind === 'respawn') {
                $reported['launch_nonce'] = $directive->json('directives.0.payload.launch_nonce');
                $reported['session_ref'] = 'solo:episode-repair-'.$index;
            }
            $poll($reported)->assertJsonCount(0, 'directives');
        }
        Date::setTestNow(now()->addSeconds(61));
        $poll($held)->assertJsonCount(0, 'directives');
        $poll($held)->assertJsonCount(0, 'directives');
    };

    resolve(WorkSweepService::class)->liveness(CarbonImmutable::now());
    $firstAnchor = $item->fresh()->stalled_at;
    expect($firstAnchor)->not->toBeNull();
    $exhaust();
    $first = WorkEscalation::query()->sole();
    resolve(WorkOrchestrationService::class)->answer(
        $first,
        $first->addressed_actor,
        'Keep the claim running.',
        CarbonImmutable::now(),
    );
    $this->postJson(resolve(WorkWorkerLinks::class)->forItem($item->fresh())['milestone'], [
        'kind' => 'note',
        'payload' => ['summary' => 'The repaired worker made progress'],
    ])->assertCreated();
    expect($item->fresh()->stalled_at)->toBeNull();

    Date::setTestNow(now()->addSeconds(61));
    resolve(WorkSweepService::class)->liveness(CarbonImmutable::now());
    $secondAnchor = $item->fresh()->stalled_at;
    expect($secondAnchor)->not->toBeNull();
    $exhaust();

    $escalations = WorkEscalation::query()->oldest()->orderBy('id')->get();
    expect($escalations)->toHaveCount(2)
        ->and($escalations->pluck('request_key')->unique())->toHaveCount(2)
        ->and($escalations[0]->request_key)->toBe('directive-exhausted:'.$item->id.':1:stall:'.$firstAnchor->getTimestamp())
        ->and($escalations[0]->answered_at)->not->toBeNull()
        ->and($escalations[1]->answered_at)->toBeNull()
        ->and($escalations[1]->request_key)->toBe('directive-exhausted:'.$item->id.':1:stall:'.$secondAnchor->getTimestamp());
});

test('progress and directive polling are safe in both serialization orders', function (): void {
    Date::setTestNow('2026-10-23 15:00:00');
    config(['capstan.work.receipt_deadline_seconds' => 60]);
    $team = workDrainTeam();
    $issued = workDrainCredential(capstanUser());
    $worker = resolve(WorkWorkerService::class);
    $directives = resolve(WorkDirectiveService::class);

    $makeClaim = function () use ($team, $issued): WorkItem {
        $item = workDrainItem(workDrainTask($team), [
            'state' => 'routed',
            'fence' => 1,
            'claimant_actor' => $issued['spoke']->actor_id,
            'claimant_spoke_id' => $issued['spoke']->id,
            'claimed_at' => now()->subMinutes(2),
            'lease_until' => now()->addMinutes(5),
            'first_fetched_at' => now(),
            'stalled_at' => now()->subSeconds(61),
        ]);

        return $item;
    };

    $pollFirst = $makeClaim();
    $milestone = $worker->milestone(
        $pollFirst->id,
        1,
        WorkMilestoneKind::Note,
        ['summary' => 'Progress after the poll identified the item'],
        afterIdentified: function () use ($directives, $team, $issued, $pollFirst): void {
            $result = $directives->poll(
                $team->id,
                $issued['spoke']->actor_id,
                $issued['spoke']->id,
                [['item_id' => $pollFirst->id, 'fence' => 1]],
                CarbonImmutable::now(),
            );
            expect($result)->toHaveCount(1);
        },
    );
    expect($milestone->exists)->toBeTrue()
        ->and($pollFirst->fresh()->stalled_at)->toBeNull()
        ->and($pollFirst->directives()->sole()->outcome)->toBe(['result' => 'resolved_by_progress']);

    $progressFirst = $makeClaim();
    $worker->milestone($progressFirst->id, 1, WorkMilestoneKind::Note, ['summary' => 'Progress committed first']);
    expect($directives->poll(
        $team->id,
        $issued['spoke']->actor_id,
        $issued['spoke']->id,
        [['item_id' => $progressFirst->id, 'fence' => 1]],
        CarbonImmutable::now()->addSeconds(61),
    ))->toBe([])
        ->and($progressFirst->fresh()->stalled_at)->toBeNull()
        ->and($progressFirst->directives()->count())->toBe(0);
});

test('stale requests and an idempotent milestone replay cannot clear a later stall episode', function (): void {
    Date::setTestNow('2026-10-23 15:30:00');
    $team = workDrainTeam();
    $issued = workDrainCredential(capstanUser());
    $item = workDrainItem(workDrainTask($team), [
        'state' => 'routed',
        'fence' => 1,
        'claimant_actor' => $issued['spoke']->actor_id,
        'claimant_spoke_id' => $issued['spoke']->id,
        'claimed_at' => now()->subMinutes(2),
        'lease_until' => now()->addMinutes(5),
        'launch_nonce' => (string) Str::uuid7(),
        'stalled_at' => now()->subMinute(),
    ]);
    $firstDirective = $item->directives()->create([
        'claimant_spoke_id' => $issued['spoke']->id,
        'fence' => 1,
        'sequence' => 1,
        'kind' => 'send_enter',
        'reason' => 'stall',
        'payload' => [],
        'status' => 'pending',
        'issued_at' => now(),
    ]);
    $payload = ['pr' => ['repo' => 'artisan-build/capstan', 'number' => 115, 'url' => 'https://github.com/artisan-build/capstan/pull/115']];
    $worker = resolve(WorkWorkerService::class);
    $first = $worker->milestone($item->id, 1, WorkMilestoneKind::PullRequestOpened, $payload, nonce: $item->launch_nonce);
    expect($firstDirective->fresh()->outcome)->toBe(['result' => 'resolved_by_progress']);

    Date::setTestNow(now()->addMinute());
    $item->update(['stalled_at' => now()]);
    $later = $item->directives()->create([
        'claimant_spoke_id' => $issued['spoke']->id,
        'fence' => 1,
        'sequence' => 2,
        'kind' => 'send_enter',
        'reason' => 'stall',
        'payload' => [],
        'status' => 'pending',
        'issued_at' => now()->addSecond(),
    ]);
    $replay = $worker->milestone($item->id, 1, WorkMilestoneKind::PullRequestOpened, $payload, nonce: $item->launch_nonce);
    expect($replay->id)->toBe($first->id)
        ->and($item->fresh()->stalled_at?->toJSON())->toBe(now()->toJSON())
        ->and($later->fresh()->status)->toBe('pending')
        ->and($item->milestones()->count())->toBe(1);

    expect(fn () => $worker->milestone($item->id, 0, WorkMilestoneKind::Note, []))->toThrow(HttpException::class)
        ->and(fn () => $worker->milestone($item->id, 1, WorkMilestoneKind::Note, [], nonce: (string) Str::uuid7()))->toThrow(HttpException::class)
        ->and($item->fresh()->stalled_at?->toJSON())->toBe(now()->toJSON())
        ->and($later->fresh()->status)->toBe('pending')
        ->and($item->milestones()->count())->toBe(1);
});

test('brief fetch settles receipt and stall directives truthfully without ending the stall', function (): void {
    Date::setTestNow('2026-10-23 16:00:00');
    config(['capstan.work.receipt_deadline_seconds' => 60]);
    $team = workDrainTeam();
    $task = workDrainTask($team);
    $task->briefRevisions()->create(['sequence' => 1, 'content' => 'Truthful settlement brief', 'author_actor' => 'creator']);
    $item = workDrainItem($task);
    $issued = workDrainCredential(capstanUser());
    $claim = $this->withHeaders($issued['headers'])->postJson(route('api.work.claim', $team), workDrainClaimPayload())->assertOk();
    $item->update(['stalled_at' => now()->subSeconds(61)]);
    $receipt = $item->directives()->create([
        'claimant_spoke_id' => $issued['spoke']->id, 'fence' => 1, 'sequence' => 1,
        'kind' => 'send_enter', 'reason' => 'receipt', 'payload' => [], 'status' => 'pending', 'issued_at' => now(),
    ]);
    $stall = $item->directives()->create([
        'claimant_spoke_id' => $issued['spoke']->id, 'fence' => 1, 'sequence' => 1,
        'kind' => 'send_enter', 'reason' => 'stall', 'payload' => [], 'status' => 'pending', 'issued_at' => now(),
    ]);

    $this->getJson($claim->json('items.0.links.brief'))->assertOk();
    expect($receipt->fresh()->outcome)->toBe(['result' => 'succeeded', 'receipt' => true])
        ->and($stall->fresh()->outcome)->toBe(['result' => 'resolved_by_fetch'])
        ->and($stall->fresh()->outcome)->not->toHaveKey('receipt')
        ->and($item->fresh()->stalled_at)->not->toBeNull();

    $held = ['item_id' => $item->id, 'fence' => 1];
    $poll = fn (array $reported) => $this->withHeaders($issued['headers'])->postJson(route('api.work.claim', $team), [
        ...workDrainClaimPayload(0),
        'orchestrator_capacity' => 0,
        'held_items' => [$reported],
    ])->assertOk();
    Date::setTestNow(now()->addSeconds(61));
    $next = $poll($held)
        ->assertJsonPath('directives.0.kind', 'resend_pointer')
        ->assertJsonPath('directives.0.reason', 'stall');
    $nextId = $next->json('directives.0.id');
    $poll([...$held, 'directive_id' => $nextId, 'directive_outcome' => 'failed']);
    $this->getJson($claim->json('items.0.links.brief'))->assertOk();
    expect(WorkDirective::query()->findOrFail($nextId)->outcome)->toBe(['result' => 'failed']);

    $fetchFirst = $item->directives()->create([
        'claimant_spoke_id' => $issued['spoke']->id, 'fence' => 1, 'sequence' => 3,
        'kind' => 'respawn', 'reason' => 'stall', 'payload' => [], 'status' => 'pending', 'issued_at' => now(),
    ]);
    $this->getJson($claim->json('items.0.links.brief'))->assertOk();
    $poll([...$held, 'directive_id' => $fetchFirst->id, 'directive_outcome' => 'failed']);
    expect($fetchFirst->fresh()->outcome)->toBe(['result' => 'resolved_by_fetch'])
        ->and($item->fresh()->stalled_at)->not->toBeNull();
});

test('stalled held items use directives and orchestrator receipts use the shorter deadline', function (): void {
    Date::setTestNow('2026-10-23 13:00:00');
    config([
        'capstan.work.receipt_deadline_seconds' => 180,
        'capstan.work.orchestration.receipt_deadline_seconds' => 60,
    ]);
    $team = workDrainTeam();
    $issued = workDrainCredential(capstanUser());
    $stalled = workDrainItem(workDrainTask($team));
    $stalledClaim = $this->withHeaders($issued['headers'])
        ->postJson(route('api.work.claim', $team), workDrainClaimPayload(1, ['builder']))->assertOk();
    $stalled->update(['stalled_at' => now()->subSeconds(181)]);

    $this->withHeaders($issued['headers'])->postJson(route('api.work.claim', $team), [
        ...workDrainClaimPayload(0, ['builder', 'orchestrator']),
        'orchestrator_capacity' => 0,
        'held_items' => [['item_id' => $stalled->id, 'fence' => $stalledClaim->json('items.0.fence')]],
    ])->assertOk()
        ->assertJsonPath('directives.0.reason', 'stall')
        ->assertJsonPath('directives.0.kind', 'send_enter');

    $orchestratorHost = workDrainCredential(capstanUser());
    $orchestratorTask = workDrainTask($team, ['user_actor_address' => $orchestratorHost['spoke']->actor_id]);
    resolve(WorkOrchestrationService::class)->event($orchestratorTask, 'short-deadline-wake', 'test_event');
    $orchestrator = $orchestratorTask->items()->where('role', WorkItem::ROLE_ORCHESTRATOR)->sole();
    $orchestratorClaim = $this->withHeaders($orchestratorHost['headers'])->postJson(route('api.work.claim', $team), [
        ...workDrainClaimPayload(0, ['builder', 'orchestrator']),
        'orchestrator_capacity' => 1,
        'held_items' => [['item_id' => $stalled->id, 'fence' => 1]],
    ])->assertOk()->assertJsonPath('items.0.id', $orchestrator->id);
    $nonce = $orchestratorClaim->json('items.0.launch_nonce');
    $this->withHeaders($orchestratorHost['headers'])->postJson(route('api.work.transition', $team), [
        'item_id' => $orchestrator->id, 'fence' => $orchestratorClaim->json('items.0.fence'), 'state' => 'delivered',
        'launch_nonce' => $nonce, 'session_ref' => 'solo:orchestrator-deadline',
    ])->assertOk();
    expect($orchestrator->fresh()->handed_off_at?->toJSON())->toBe(now()->toJSON())
        ->and($orchestrator->fresh()->first_fetched_at)->toBeNull();

    Date::setTestNow(now()->addSeconds(61));
    $this->withHeaders($orchestratorHost['headers'])->postJson(route('api.work.claim', $team), [
        ...workDrainClaimPayload(0, ['builder', 'orchestrator']),
        'orchestrator_capacity' => 0,
        'held_items' => [['item_id' => $orchestrator->id, 'fence' => $orchestratorClaim->json('items.0.fence')]],
    ])->assertOk()
        ->assertJsonPath('directives.0.item_id', $orchestrator->id)
        ->assertJsonPath('directives.0.reason', 'receipt')
        ->assertJsonPath('directives.0.kind', 'send_enter');
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
    expect(array_keys($claim->json('items.0.links')))->toBe(['brief', 'milestone', 'steers', 'evidence', 'orchestration', 'orchestrate']);
    $this->getJson($claim->json('items.0.links.orchestration'))->assertOk()
        ->assertJsonPath('context.task.id', $task->id)
        ->assertJsonPath('context.brief.content', 'Orchestrator brief')
        ->assertJsonCount(1, 'context.events');

    $renew = $this->withHeaders($issued['headers'])->postJson(route('api.work.renew', $team), [
        'items' => [['item_id' => $claim->json('items.0.id'), 'fence' => $claim->json('items.0.fence')]],
    ])->assertOk()->assertJsonPath('results.0.status', 'renewed');
    expect(array_keys($renew->json('results.0.item.links')))->toBe(['brief', 'milestone', 'steers', 'evidence', 'orchestration', 'orchestrate']);
    $this->getJson($renew->json('results.0.item.links.orchestration'))->assertOk();
});

test('an orchestrator claim returns the holder stored session ref and a turn cannot end acted without turn_done', function (): void {
    // AC19 clauses: "each returns A's stored session_ref", and "a turn without turn_done is not marked acted".
    // Both hold in production and neither was asserted at the HTTP boundary: nulling the response field in
    // WorkDrainController::claimItem, or dropping the turn_done guard in WorkWorkerService::milestone, left
    // the whole Work suite green.
    Date::setTestNow('2026-10-17 10:00:00');
    $team = workDrainTeam();
    $task = workDrainTask($team);
    $task->briefRevisions()->create(['sequence' => 1, 'content' => 'Turn brief', 'author_actor' => 'creator']);
    $user = capstanUser();
    $issued = workDrainCredential($user);
    $engine = resolve(WorkOrchestrationService::class);

    // Take a first turn so the drain's credential-backed spoke exists and holds the seat.
    $engine->event($task, 'session-ref-first', 'test_event', ['safe' => true]);
    $first = $this->withHeaders($issued['headers'])
        ->postJson(route('api.work.claim', $team), workDrainClaimPayload(1, ['orchestrator']))->assertOk();
    $firstTurn = WorkItem::query()->findOrFail($first->json('items.0.id'));
    $spoke = Spoke::query()->where('actor_id', (string) $user->getKey())->sole();
    $seat = WorkOrchestratorSeat::query()->where('work_task_id', $task->id)->sole();
    $engine->turnDone($firstTurn, $firstTurn->fence, (int) $seat->fence, 'First state', CarbonImmutable::now());

    // Host A stores an opaque reference on the seat it already holds.
    $ref = 'opaque://Session/Exact ? Case=YES '.Str::random(12);
    resolve(WorkSeatService::class)->take($task, (string) $user->getKey(), $spoke->id, (int) $seat->fresh()->fence, $ref, false, CarbonImmutable::now());

    // The next claim for that parent hands the reference back, with the seat fence it belongs to.
    $engine->event($task, 'session-ref-second', 'test_event', ['safe' => true]);
    $claim = $this->withHeaders($issued['headers'])
        ->postJson(route('api.work.claim', $team), workDrainClaimPayload(1, ['orchestrator']))->assertOk();
    expect($claim->json('items.0.session_ref'))->toBe($ref)
        ->and($claim->json('items.0.seat_fence'))->toBe((int) $seat->fresh()->fence);

    // Drive the turn to routed, so the only thing left that can refuse a done milestone is the
    // turn_done guard. Without this the generic "Illegal transition" guard answers 422 first and the
    // assertion below passes whether or not the turn_done guard exists.
    foreach (['delivered', 'routed'] as $state) {
        $this->withHeaders($issued['headers'])->postJson(route('api.work.transition', $team), [
            'item_id' => $claim->json('items.0.id'),
            'fence' => $claim->json('items.0.fence'),
            'state' => $state,
        ])->assertOk();
    }
    expect(WorkItem::query()->findOrFail($claim->json('items.0.id'))->state)->toBe(WorkItemState::Routed);

    // A done milestone on a routed orchestrator turn is still refused: only turn_done may end one.
    $this->postJson($claim->json('items.0.links.milestone'), ['kind' => 'done', 'payload' => (object) []])
        ->assertStatus(422);

    $turn = WorkItem::query()->findOrFail($claim->json('items.0.id'));
    expect($turn->state)->toBe(WorkItemState::Routed)
        ->and($turn->state)->not->toBe(WorkItemState::Acted)
        ->and($turn->milestones()->where('kind', 'done')->count())->toBe(0);
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
        ->and(Spoke::query()->count())->toBe(1);
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
    $callerSpoke = $issued['spoke'];
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
    $spoke = $issued['spoke'];
    $spoke->update(['last_cursor' => 'postmaster-cursor']);
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

    // The first claimant remains the deterministic winner until its 180-second presence expires.
    Date::setTestNow('2026-10-17 13:03:01');
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

test('work is available without a product feature gate and other bound purposes cannot authorize it', function (): void {
    $team = workDrainTeam();
    $user = capstanUser();
    $work = workDrainCredential($user);
    foreach (['claim', 'transition', 'renew'] as $action) {
        $this->withHeaders($work['headers'])
            ->postJson(route("api.work.$action", $team), [])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');
    }

    $artifact = capstanBoundBearer($user, CapstanCredentialDeclaration::ARTIFACT_INGEST);
    $this->withHeaders([
        'Authorization' => 'Bearer '.$artifact['token'],
        CapstanCredentialDeclaration::ACTOR_HEADER => (string) $user->getKey(),
    ])->postJson(route('api.work.claim', $team), workDrainClaimPayload())
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
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
        ->and(Spoke::query()->count())->toBe(1);
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
