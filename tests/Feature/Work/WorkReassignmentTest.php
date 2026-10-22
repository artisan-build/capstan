<?php

use App\Enums\WorkItemState;
use App\Enums\WorkPriority;
use App\Models\Spoke;
use App\Models\Team;
use App\Models\WorkEscalation;
use App\Models\WorkItem;
use App\Models\WorkOrchestrationEvent;
use App\Models\WorkOrchestratorSeat;
use App\Models\WorkTask;
use App\Notifications\WorkNotification;
use App\Work\WorkClaimService;
use App\Work\WorkItemStateService;
use App\Work\WorkOrchestrationService;
use App\Work\WorkOrchestrationSurface;
use App\Work\WorkReassignmentService;
use App\Work\WorkWorkerLinks;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('r', 32)),
        'capstan.features.artifacts' => false,
        'capstan.work.lease_seconds' => 300,
        'capstan.work.orchestration.turn_budget' => 20,
    ]);
});

function reassignmentToken(User $user): string
{
    $token = Str::random(40);
    Credential::query()->create([
        'kind' => CredentialKind::Bearer,
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::UserPrincipal,
        'subject_ref' => 'capstan-user:'.$user->id,
        'user_id' => (string) $user->id,
        'name' => 'Reassignment test',
        'abilities' => [],
        'secret_hash' => hash('sha256', $token),
        'status' => CredentialStatus::Active,
    ]);

    return $token;
}

function reassignmentCall(array|object $arguments, string $token): TestResponse
{
    return test()->postJson('/mcp/write', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'reassign_task', 'arguments' => $arguments],
    ], ['Authorization' => 'Bearer '.$token]);
}

/** @return array<string, mixed> */
function reassignmentResult(array $arguments, string $token): array
{
    $result = reassignmentCall($arguments, $token)->assertOk()->json('result.structuredContent');
    expect($result)->toBeArray();

    return $result;
}

function reassignmentTask(User $owner, ?WorkTask $parent = null, ?Team $team = null): WorkTask
{
    $team ??= $parent?->team ?? Team::default();

    return WorkTask::query()->create([
        'team_id' => $team->id,
        'parent_task_id' => $parent?->id,
        'title' => 'Reassignment '.Str::random(8),
        'user_actor_address' => (string) $owner->id,
        'required_capabilities' => [],
        'timebox_minutes' => 30,
        'creator_actor' => (string) $owner->id,
        'owner_actor' => (string) $owner->id,
    ]);
}

function reassignmentSpoke(User $owner): Spoke
{
    return Spoke::query()->create([
        'actor_id' => (string) $owner->id,
        'credential_id' => (string) Str::uuid7(),
        'last_polled_at' => now(),
    ]);
}

/** @param list<string> $fields
 * @return array<string, mixed>
 */
function reassignmentRaw(object $model, array $fields): array
{
    return array_intersect_key($model->getRawOriginal(), array_flip($fields));
}

/** @return list<WorkItem> */
function reassignmentClaims(Team $team, User $owner, Spoke $spoke, array $roles): array
{
    return resolve(WorkClaimService::class)->claim(
        $team->id,
        (string) $owner->id,
        $spoke->id,
        $roles,
        [],
        25,
        CarbonImmutable::now(),
        300,
    );
}

test('move cascades ownership fences the orchestrator and preserves a running ordinary claim', function (): void {
    $oldOwner = capstanUser(['role' => 'member']);
    $newOwner = capstanUser(['role' => 'member']);
    $team = Team::default();
    $root = reassignmentTask($oldOwner, team: $team);
    $child = reassignmentTask($oldOwner, $root);
    $grandchild = reassignmentTask($oldOwner, $child);
    $oldSpoke = reassignmentSpoke($oldOwner);
    $newSpoke = reassignmentSpoke($newOwner);
    $root->update(['device_spoke_id' => $oldSpoke->id, 'failover' => false]);
    $child->update(['device_spoke_id' => $newSpoke->id, 'failover' => false]);
    $grandchild->update(['device_spoke_id' => $oldSpoke->id, 'failover' => false]);
    $engine = resolve(WorkOrchestrationService::class);
    $engine->event($root, 'before-move', 'test_event');
    $turn = reassignmentClaims($team, $oldOwner, $oldSpoke, [WorkItem::ROLE_ORCHESTRATOR])[0];
    $links = resolve(WorkWorkerLinks::class)->forItem($turn);
    $leaf = $grandchild->items()->create(['role' => 'builder']);
    $running = reassignmentClaims($team, $oldOwner, $oldSpoke, ['builder'])[0];
    $states = resolve(WorkItemStateService::class);
    $running = $states->transitionClaim($running, WorkItemState::Delivered, $team->id, $oldSpoke->id, (string) $oldOwner->id, $running->fence, CarbonImmutable::now());
    $running = $states->transitionClaim($running, WorkItemState::Routed, $team->id, $oldSpoke->id, (string) $oldOwner->id, $running->fence, CarbonImmutable::now());
    expect($running)->toBeInstanceOf(WorkItem::class)->and($running->id)->toBe($leaf->id);
    $runningFields = ['state', 'claimant_spoke_id', 'claimant_actor', 'lease_until', 'fence'];
    $runningBefore = reassignmentRaw($running, $runningFields);
    $seatBefore = WorkOrchestratorSeat::query()->where('work_task_id', $root->id)->sole();

    $result = reassignmentResult([
        'team_id' => $team->id,
        'task_id' => $root->id,
        'to_actor' => (string) $newOwner->id,
        'mode' => 'move',
        'note' => 'Handing off the root',
        'idempotency_key' => 'move-root-once',
    ], reassignmentToken($oldOwner));

    expect($result)->toMatchArray([
        'outcome' => 'accepted',
        'task_id' => $root->id,
        'old_owner' => (string) $oldOwner->id,
        'new_owner' => (string) $newOwner->id,
        'mode' => 'move',
    ]);
    foreach ([$root, $child, $grandchild] as $task) {
        expect($task->fresh()->owner_actor)->toBe((string) $newOwner->id)
            ->and($task->fresh()->effectiveEscalationActor())->toBe((string) $newOwner->id)
            ->and($task->fresh()->user_actor_address)->toBe((string) $newOwner->id);
    }
    expect($root->fresh()->device_spoke_id)->toBeNull()
        ->and($child->fresh()->device_spoke_id)->toBe($newSpoke->id)
        ->and($grandchild->fresh()->device_spoke_id)->toBeNull();

    $seatAfter = $seatBefore->fresh();
    expect($seatAfter->fence)->toBeGreaterThan($seatBefore->fence)
        ->and($seatAfter->holder_actor)->toBeNull()
        ->and($seatAfter->holder_spoke_id)->toBeNull()
        ->and($seatAfter->session_ref)->toBeNull()
        ->and(reassignmentRaw($running->fresh(), $runningFields))->toBe($runningBefore);
    expect(fn () => $engine->turnDone($turn, $turn->fence, $turn->seat_fence, 'Stale turn', CarbonImmutable::now()))
        ->toThrow(DomainException::class);
    $this->postJson($links['orchestrate'], [
        'action' => 'finish',
        'input' => ['state' => 'acted', 'content' => 'Stale signed turn'],
        'idempotency_key' => 'stale-after-reassignment',
    ])->assertForbidden();
    expect(reassignmentClaims($team, $oldOwner, $oldSpoke, [WorkItem::ROLE_ORCHESTRATOR]))->toBe([]);
    $replacement = reassignmentClaims($team, $newOwner, $newSpoke, [WorkItem::ROLE_ORCHESTRATOR]);
    expect($replacement)->toHaveCount(1)->and($replacement[0]->id)->toBe($turn->id);

    $finished = $states->transitionClaim($running->fresh(), WorkItemState::Acted, $team->id, $oldSpoke->id, (string) $oldOwner->id, $running->fence, CarbonImmutable::now());
    expect($finished)->toBeInstanceOf(WorkItem::class)->and($finished->state)->toBe(WorkItemState::Acted);
    $later = $grandchild->items()->create(['role' => 'builder']);
    expect(reassignmentClaims($team, $oldOwner, $oldSpoke, ['builder']))->toBe([]);
    expect(reassignmentClaims($team, $newOwner, $newSpoke, ['builder']))->toHaveCount(1)
        ->and($later->fresh()->claimant_actor)->toBe((string) $newOwner->id);

    $event = WorkOrchestrationEvent::query()->where('kind', 'task_reassigned')->sole();
    expect($event->work_task_id)->toBe($root->id)
        ->and($event->wakes)->toBeTrue()
        ->and($event->evidence)->toBe([
            'old_owner' => (string) $oldOwner->id,
            'new_owner' => (string) $newOwner->id,
            'old_escalation_actor' => (string) $oldOwner->id,
            'new_escalation_actor' => (string) $newOwner->id,
            'mode' => 'move',
            'note' => 'Handing off the root',
        ])
        ->and($newOwner->fresh()->notifications)->toHaveCount(1)
        ->and($newOwner->fresh()->notifications->sole()->type)->toBe(WorkNotification::class)
        ->and($oldOwner->fresh()->notifications)->toBeEmpty();
});

test('escalations mode preserves live authority and readdresses only unanswered escalation pairs', function (): void {
    $oldOwner = capstanUser(['role' => 'member']);
    $newOwner = capstanUser(['role' => 'member']);
    $explicit = capstanUser(['role' => 'member']);
    $team = Team::default();
    $root = reassignmentTask($oldOwner, team: $team);
    $child = reassignmentTask($oldOwner, $root);
    $oldSpoke = reassignmentSpoke($oldOwner);
    $newSpoke = reassignmentSpoke($newOwner);
    $root->update(['device_spoke_id' => $oldSpoke->id, 'failover' => false]);
    $child->update(['device_spoke_id' => $oldSpoke->id, 'failover' => false]);
    $engine = resolve(WorkOrchestrationService::class);
    expect($root->escalation_actor)->toBeNull()
        ->and($root->effectiveEscalationActor())->toBe((string) $oldOwner->id);
    $fallback = $engine->escalate($root, 'fallback-before-reassignment', null, 'Fallback recipient?', ['Continue'], 'Continue');
    expect($fallback->addressed_actor)->toBe((string) $oldOwner->id);
    $engine->event($root, 'before-escalations', 'test_event');
    $turn = reassignmentClaims($team, $oldOwner, $oldSpoke, [WorkItem::ROLE_ORCHESTRATOR])[0];
    $leaf = $child->items()->create(['role' => 'builder']);
    $running = reassignmentClaims($team, $oldOwner, $oldSpoke, ['builder'])[0];
    $states = resolve(WorkItemStateService::class);
    $running = $states->transitionClaim($running, WorkItemState::Delivered, $team->id, $oldSpoke->id, (string) $oldOwner->id, $running->fence, CarbonImmutable::now());
    $running = $states->transitionClaim($running, WorkItemState::Routed, $team->id, $oldSpoke->id, (string) $oldOwner->id, $running->fence, CarbonImmutable::now());
    expect($running?->id)->toBe($leaf->id);

    $openRoot = $engine->escalate($root, 'open-root', (string) $oldOwner->id, 'Root question', ['Continue'], 'Continue');
    $openChild = $engine->escalate($child, 'open-child', (string) $oldOwner->id, 'Child question', ['Continue'], 'Continue');
    $answered = $engine->escalate($child, 'answered-child', (string) $oldOwner->id, 'Answered question', ['Continue'], 'Continue');
    $engine->answer($answered, (string) $oldOwner->id, 'Done', CarbonImmutable::now());
    DB::table('notifications')->delete();
    $seatFields = ['holder_actor', 'holder_spoke_id', 'session_ref', 'interactive', 'lease_until', 'fence', 'active_item_id'];
    $seatBefore = reassignmentRaw(WorkOrchestratorSeat::query()->where('work_task_id', $root->id)->sole(), $seatFields);
    $runningFields = ['state', 'claimant_spoke_id', 'claimant_actor', 'lease_until', 'fence'];
    $runningBefore = reassignmentRaw($running, $runningFields);

    reassignmentResult([
        'team_id' => $team->id,
        'task_id' => $root->id,
        'to_actor' => (string) $newOwner->id,
        'mode' => 'escalations',
        'idempotency_key' => 'escalations-root-once',
    ], reassignmentToken($oldOwner));

    expect(reassignmentRaw(WorkOrchestratorSeat::query()->where('work_task_id', $root->id)->sole(), $seatFields))->toBe($seatBefore)
        ->and(reassignmentRaw($running->fresh(), $runningFields))->toBe($runningBefore);
    foreach ([$root, $child] as $task) {
        expect($task->fresh()->owner_actor)->toBe((string) $oldOwner->id)
            ->and($task->fresh()->user_actor_address)->toBe((string) $oldOwner->id)
            ->and($task->fresh()->effectiveEscalationActor())->toBe((string) $newOwner->id)
            ->and($task->fresh()->device_spoke_id)->toBe($oldSpoke->id);
    }
    foreach ([$openRoot, $openChild] as $open) {
        expect($open->fresh()->addressed_actor)->toBe((string) $newOwner->id)
            ->and(WorkItem::query()->findOrFail($open->work_item_id)->addressed_actor)->toBe((string) $newOwner->id);
    }
    expect($answered->fresh()->addressed_actor)->toBe((string) $oldOwner->id)
        ->and(WorkItem::query()->findOrFail($answered->work_item_id)->addressed_actor)->toBe((string) $oldOwner->id)
        ->and(WorkItem::query()->findOrFail($answered->work_item_id)->state)->toBe(WorkItemState::Acted)
        ->and(WorkOrchestrationEvent::query()->where('kind', 'task_reassigned')->count())->toBe(1)
        ->and($newOwner->fresh()->notifications)->toHaveCount(1);

    $surface = resolve(WorkOrchestrationSurface::class);
    $context = $surface->claimed($turn->id, $turn->fence);
    $created = $surface->act($context, 'create_child', [
        'under_task_id' => $root->id,
        'title' => 'Escalations-mode child',
        'brief' => 'Work ownership stays with the old owner.',
    ], CarbonImmutable::now());
    $createdChild = WorkTask::query()->findOrFail($created['id']);
    expect($createdChild->owner_actor)->toBe((string) $oldOwner->id)
        ->and($createdChild->user_actor_address)->toBe((string) $oldOwner->id)
        ->and($createdChild->effectiveEscalationActor())->toBe((string) $newOwner->id);
    $defaulted = $surface->act($context, 'escalate', [
        'key' => 'default-after-reassignment',
        'question' => 'Who receives this?',
        'options' => ['Continue'],
        'recommendation' => 'Continue',
    ], CarbonImmutable::now());
    $explicitResult = $surface->act($context, 'escalate', [
        'key' => 'explicit-after-reassignment',
        'addressed_actor' => (string) $explicit->id,
        'question' => 'Explicit recipient?',
        'options' => ['Continue'],
        'recommendation' => 'Continue',
    ], CarbonImmutable::now());
    expect(WorkEscalation::query()->findOrFail($defaulted['id'])->addressed_actor)->toBe((string) $newOwner->id)
        ->and(WorkEscalation::query()->findOrFail($explicitResult['id'])->addressed_actor)->toBe((string) $explicit->id)
        ->and($newOwner->fresh()->notifications)->toHaveCount(2)
        ->and($explicit->fresh()->notifications)->toHaveCount(1);

    $later = $child->items()->create(['role' => 'later-builder']);
    expect(reassignmentClaims($team, $newOwner, $newSpoke, ['later-builder']))->toBe([]);
    expect(reassignmentClaims($team, $oldOwner, $oldSpoke, ['later-builder']))->toHaveCount(1)
        ->and($later->fresh()->claimant_actor)->toBe((string) $oldOwner->id);
});

test('authorization targets exact input and durable replay are enforced without duplicate effects', function (): void {
    $owner = capstanUser(['role' => 'member']);
    $target = capstanUser(['role' => 'member']);
    $otherTarget = capstanUser(['role' => 'member']);
    $team = Team::default();
    $root = reassignmentTask($owner, team: $team);
    $token = reassignmentToken($owner);
    $arguments = [
        'team_id' => $team->id,
        'task_id' => $root->id,
        'to_actor' => (string) $target->id,
        'mode' => 'move',
        'idempotency_key' => 'owner-reassignment',
    ];
    expect(reassignmentResult($arguments, $token)['outcome'])->toBe('accepted')
        ->and(reassignmentResult($arguments, $token)['outcome'])->toBe('already_accepted')
        ->and(WorkOrchestrationEvent::query()->where('kind', 'task_reassigned')->count())->toBe(1)
        ->and($target->fresh()->notifications)->toHaveCount(1);

    $admin = capstanUser(['role' => 'admin']);
    $managed = reassignmentTask($owner, team: $team);
    expect(reassignmentResult([
        'team_id' => $team->id,
        'task_id' => $managed->id,
        'to_actor' => (string) $otherTarget->id,
        'mode' => 'escalations',
        'idempotency_key' => 'admin-reassignment',
    ], reassignmentToken($admin))['outcome'])->toBe('accepted');

    $unrelated = capstanUser(['role' => 'member']);
    $forbidden = reassignmentResult([
        'team_id' => $team->id,
        'task_id' => reassignmentTask($owner, team: $team)->id,
        'to_actor' => (string) $target->id,
        'mode' => 'move',
        'idempotency_key' => 'unrelated-member',
    ], reassignmentToken($unrelated));
    expect($forbidden)->toBe(['outcome' => 'refused', 'reason' => 'reassignment_forbidden']);

    $foreignTeam = Team::query()->create(['name' => 'Foreign', 'slug' => Str::random(12)]);
    $foreignOwner = capstanUser(['role' => 'member']);
    DB::table('team_user')->insert(['team_id' => $foreignTeam->id, 'user_id' => $foreignOwner->id, 'created_at' => now(), 'updated_at' => now()]);
    $foreignTarget = capstanUser(['role' => 'member']);
    DB::table('team_user')->insert(['team_id' => $foreignTeam->id, 'user_id' => $foreignTarget->id, 'created_at' => now(), 'updated_at' => now()]);
    $foreignRoot = reassignmentTask($foreignOwner, team: $foreignTeam);
    $outsider = capstanUser(['role' => 'admin']);
    expect(reassignmentResult([
        'team_id' => $foreignTeam->id,
        'task_id' => $foreignRoot->id,
        'to_actor' => (string) $foreignTarget->id,
        'mode' => 'move',
        'idempotency_key' => 'cross-team-manager',
    ], reassignmentToken($outsider)))->toBe(['outcome' => 'refused', 'reason' => 'task_unavailable']);

    $inactive = capstanUser(['status' => 'inactive']);
    $offboarded = capstanUser();
    OffboardedSubject::query()->create([
        'subject_type' => SubjectType::UserPrincipal,
        'subject_ref' => 'capstan-user:'.$offboarded->id,
        'user_id' => (string) $offboarded->id,
        'offboarded_at' => now(),
    ]);
    foreach ([$inactive, $offboarded] as $invalid) {
        expect(reassignmentResult([
            'team_id' => $team->id,
            'task_id' => reassignmentTask($owner, team: $team)->id,
            'to_actor' => (string) $invalid->id,
            'mode' => 'move',
            'idempotency_key' => 'invalid-target-'.$invalid->id,
        ], reassignmentToken($owner)))->toBe(['outcome' => 'refused', 'reason' => 'invalid_target']);
    }

    $foreignOnlyTarget = capstanUser();
    expect(reassignmentResult([
        'team_id' => $foreignTeam->id,
        'task_id' => $foreignRoot->id,
        'to_actor' => (string) $foreignOnlyTarget->id,
        'mode' => 'move',
        'idempotency_key' => 'foreign-target',
    ], reassignmentToken($foreignOwner)))->toBe(['outcome' => 'refused', 'reason' => 'invalid_target']);

    $childRoot = reassignmentTask($owner, team: $team);
    $child = reassignmentTask($owner, $childRoot);
    expect(reassignmentResult([
        'team_id' => $team->id,
        'task_id' => $child->id,
        'to_actor' => (string) $target->id,
        'mode' => 'move',
        'idempotency_key' => 'child-target',
    ], reassignmentToken($owner)))->toBe(['outcome' => 'refused', 'reason' => 'task_unavailable']);

    $p0Source = reassignmentTask($owner, team: $team);
    $p0Source->update(['priority' => WorkPriority::P0]);
    $p0Target = reassignmentTask($target, team: $team);
    $p0Target->update(['priority' => WorkPriority::P0]);
    expect(reassignmentResult([
        'team_id' => $team->id,
        'task_id' => $p0Source->id,
        'to_actor' => (string) $target->id,
        'mode' => 'move',
        'idempotency_key' => 'p0-conflict',
    ], reassignmentToken($owner)))->toBe(['outcome' => 'refused', 'reason' => 'active_p0_exists']);
});

test('inactive and offboarded callers are rejected before durable claims', function (string $condition): void {
    $owner = capstanUser(['role' => 'member']);
    $target = capstanUser(['role' => 'member']);
    $root = reassignmentTask($owner);
    $token = reassignmentToken($owner);
    if ($condition === 'inactive') {
        $owner->forceFill(['status' => 'inactive'])->save();
    } else {
        OffboardedSubject::query()->create([
            'subject_type' => SubjectType::UserPrincipal,
            'subject_ref' => 'capstan-user:'.$owner->id,
            'user_id' => (string) $owner->id,
            'offboarded_at' => now(),
        ]);
    }

    $response = reassignmentCall([
        'team_id' => Team::default()->id,
        'task_id' => $root->id,
        'to_actor' => (string) $target->id,
        'mode' => 'move',
        'idempotency_key' => 'denied-caller-'.$condition,
    ], $token);
    expect($response->status() !== 200 || $response->json('result.isError') === true)->toBeTrue();
    expect(DB::table('mcp_write_claims')->count())->toBe(0)
        ->and($root->fresh()->owner_actor)->toBe((string) $owner->id);
})->with(['inactive', 'offboarded']);

test('reassignment rejects unknown fields wrong scalar types invalid UUIDs modes and note bounds before effects', function (array|object $arguments): void {
    $owner = capstanUser(['role' => 'member']);
    $target = capstanUser(['role' => 'member']);
    $root = reassignmentTask($owner);
    $base = [
        'team_id' => Team::default()->id,
        'task_id' => $root->id,
        'to_actor' => (string) $target->id,
        'mode' => 'move',
        'idempotency_key' => 'invalid-input',
    ];
    $arguments = is_array($arguments) ? array_replace($base, $arguments) : $arguments;
    reassignmentCall($arguments, reassignmentToken($owner))->assertOk()->assertJsonPath('result.isError', true);
    expect($root->fresh()->owner_actor)->toBe((string) $owner->id)
        ->and(WorkOrchestrationEvent::query()->count())->toBe(0)
        ->and(DB::table('notifications')->count())->toBe(0);
})->with([
    'unknown field' => [['unknown' => true]],
    'integer actor' => [['to_actor' => 123]],
    'invalid task UUID' => [['task_id' => 'not-a-uuid']],
    'invalid mode' => [['mode' => 'all']],
    'long note' => [['note' => str_repeat('n', 2001)]],
]);

test('a notification insert failure leaves the published durable key retryable without partial effects', function (): void {
    $owner = capstanUser(['role' => 'member']);
    $target = capstanUser(['role' => 'member']);
    $root = reassignmentTask($owner);
    $token = reassignmentToken($owner);
    $engine = resolve(WorkOrchestrationService::class);
    $open = $engine->escalate($root, 'rollback-open', (string) $owner->id, 'Rollback question', ['Continue'], 'Continue');
    $engine->event($root, 'rollback-turn', 'test_event');
    $spoke = reassignmentSpoke($owner);
    $turn = reassignmentClaims(Team::default(), $owner, $spoke, [WorkItem::ROLE_ORCHESTRATOR])[0];
    DB::table('notifications')->delete();
    $seatFields = ['holder_actor', 'holder_spoke_id', 'session_ref', 'interactive', 'lease_until', 'fence', 'active_item_id'];
    $seatBefore = reassignmentRaw(WorkOrchestratorSeat::query()->where('work_task_id', $root->id)->sole(), $seatFields);
    $turnFields = ['state', 'claimant_spoke_id', 'claimant_actor', 'lease_until', 'fence', 'seat_fence'];
    $turnBefore = reassignmentRaw($turn, $turnFields);
    $eventsBefore = WorkOrchestrationEvent::query()->count();
    $arguments = [
        'team_id' => Team::default()->id,
        'task_id' => $root->id,
        'to_actor' => (string) $target->id,
        'mode' => WorkReassignmentService::MODE_MOVE,
        'idempotency_key' => 'notification-failure',
    ];
    Schema::drop('notifications');

    $failed = reassignmentCall($arguments, $token)->assertOk();
    expect($failed->json('result.isError'))->toBeTrue()
        ->and($failed->json('result.content.0.text'))->toBe('The write service is temporarily unavailable.')
        ->and($failed->getContent())->not->toContain('notifications')
        ->and($failed->getContent())->not->toContain('SQLSTATE');
    expect($root->fresh()->owner_actor)->toBe((string) $owner->id)
        ->and($root->fresh()->escalation_actor)->toBeNull()
        ->and($open->fresh()->addressed_actor)->toBe((string) $owner->id)
        ->and(WorkItem::query()->findOrFail($open->work_item_id)->addressed_actor)->toBe((string) $owner->id)
        ->and(WorkOrchestrationEvent::query()->count())->toBe($eventsBefore)
        ->and(reassignmentRaw(WorkOrchestratorSeat::query()->where('work_task_id', $root->id)->sole(), $seatFields))->toBe($seatBefore)
        ->and(reassignmentRaw($turn->fresh(), $turnFields))->toBe($turnBefore)
        ->and(DB::table('mcp_write_claims')->where('idempotency_key', 'notification-failure')->count())->toBe(0)
        ->and(DB::table('mcp_write_effects')->count())->toBe(0);

    Schema::create('notifications', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('type');
        $table->morphs('notifiable');
        $table->text('data');
        $table->timestamp('read_at')->nullable();
        $table->timestamps();
    });
    $result = reassignmentResult($arguments, $token);
    expect($result['outcome'])->toBe('accepted')
        ->and($result['new_owner'])->toBe((string) $target->id)
        ->and($result['new_escalation_actor'])->toBe((string) $target->id)
        ->and($root->fresh()->owner_actor)->toBe((string) $target->id)
        ->and(WorkOrchestrationEvent::query()->where('kind', 'task_reassigned')->count())->toBe(1)
        ->and($target->fresh()->notifications)->toHaveCount(1)
        ->and(DB::table('mcp_write_claims')->where('idempotency_key', 'notification-failure')->value('state'))->toBe('accepted')
        ->and(DB::table('mcp_write_effects')->count())->toBe(1);
});
