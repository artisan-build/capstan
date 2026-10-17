<?php

use App\Mcp\CapstanServer;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListPagesTool;
use App\Mcp\Tools\ListWorkTool;
use App\Mcp\Tools\OrchestrateTool;
use App\Mcp\Tools\QueueItemTool;
use App\Mcp\Tools\TakeSeatTool;
use App\Models\Envelope;
use App\Models\Inbox;
use App\Models\Spoke;
use App\Models\Team;
use App\Models\WorkOrchestratorSeat;
use App\Models\WorkTask;
use ArtisanBuild\BuiltForCloud\AuthorityMode;
use ArtisanBuild\BuiltForCloud\Console\Assertion;
use ArtisanBuild\BuiltForCloud\Console\AssertionPurpose;
use ArtisanBuild\BuiltForCloud\Console\ConsoleRole;
use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
use ArtisanBuild\BuiltForCloud\Console\RequestAssertion;
use ArtisanBuild\BuiltForCloud\Hmac\SigningRootLifecycle;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RequestEffectCeiling;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\McpDelegatedTools;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function (): void {
    config([
        'app.debug' => false,
        'app.key' => 'base64:'.base64_encode(str_repeat('d', 32)),
        'built-for-cloud.managed.client_secret' => str_repeat('s', 64),
        'capstan.features.work' => true,
        'capstan.features.postmaster' => true,
        'capstan.features.artifacts' => true,
        'capstan.artifacts.render_origin' => 'https://render.delegated-principal.test',
        'capstan.postmaster.server_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
    ]);
    Feature::flushCache();
    Storage::fake();
    resolve(SigningRootLifecycle::class)->provision();

    DB::table('bfc_authority')->where('key', InstallationAuthority::KEY)->update([
        'mode' => AuthorityMode::Managed->value,
        'generation' => 2,
        'issuer' => 'https://scalpels.test',
        'connection_id' => 'delegated-principal-connection',
        'organization_id' => 'delegated-principal-organization',
        'installation_id' => 'delegated-principal-installation',
        'authority_base_url' => 'https://scalpels.test',
        'managed_connection_status' => 'active',
        'managed_connection_generation' => 2,
        'managed_connection_roster_version' => 1,
        'managed_connection_response_sequence' => 1,
    ]);
});

function delegatedPrincipalUser(string $scalpelsId, UserRole $role = UserRole::Owner): User
{
    return capstanUser([
        'role' => $role->value,
        'scalpels_issuer' => 'https://scalpels.test',
        'scalpels_connection_id' => 'delegated-principal-connection',
        'scalpels_id' => $scalpelsId,
        'managed_membership_status' => 'active',
        'managed_membership_role' => $role->value,
        'managed_membership_generation' => 2,
        'managed_membership_roster_version' => 1,
        'managed_membership_response_sequence' => 1,
        'managed_membership_responded_at' => now()->toAtomString(),
        'membership_confirmed_at' => now(),
    ]);
}

/** @param array<string, mixed> $arguments */
function publishDelegatedPrincipal(
    string $subject,
    array $arguments = [],
    ConsoleRole $role = ConsoleRole::Admin,
): DelegatedActor {
    $now = CarbonImmutable::now();
    $assertion = Assertion::fromVerifiedClaims(
        issuer: 'https://scalpels.test',
        subject: $subject,
        displayName: 'Scalpels relay user',
        role: $role,
        onBehalfOf: 'Relay test team',
        audience: 'capstan-test-installation',
        issuedAt: $now->subSecond(),
        expiresAt: $now->addSeconds(90),
        keyId: 'delegated-principal-key',
        id: (string) Str::uuid(),
        purpose: AssertionPurpose::Mcp,
    );
    $actor = DelegatedActor::recordHandoff($assertion);
    $request = Request::create(
        '/mcp/delegated',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['arguments' => (object) $arguments],
        ], JSON_THROW_ON_ERROR),
    );
    RequestAssertion::publish($request, $actor, $assertion);
    RequestEffectCeiling::publish($request, Effect::Write->value);
    app()->instance('request', $request);

    return $actor;
}

test('the Scalpels delegated principal reads and writes Work as its managed Capstan user', function (): void {
    $user = delegatedPrincipalUser('scalpels-user-101');
    $team = Team::query()->create([
        'name' => 'Delegated Work team',
        'slug' => 'delegated-work-team',
        'pool_work' => true,
    ]);
    $create = [
        'team_id' => $team->id,
        'idempotency_key' => 'delegated-create',
        'title' => 'Relayed task',
        'brief' => 'Created through the delegated principal.',
    ];
    publishDelegatedPrincipal('scalpels-user-101', $create);
    CapstanServer::tool(CreateTaskTool::class, $create)->assertOk();

    $task = WorkTask::query()->sole();
    expect($task->creator_actor)->toBe((string) $user->getKey());

    $get = [
        'team_id' => $team->id,
        'task_id' => $task->id,
    ];
    publishDelegatedPrincipal('scalpels-user-101', $get);
    CapstanServer::tool(GetTaskTool::class, $get)->assertOk();

    $take = [
        'team_id' => $team->id,
        'task_id' => $task->id,
        'session_ref' => 'delegated-session',
        'idempotency_key' => 'delegated-seat',
    ];
    publishDelegatedPrincipal('scalpels-user-101', $take);
    CapstanServer::tool(TakeSeatTool::class, $take)->assertOk();

    $spoke = Spoke::query()->sole();
    expect($spoke->actor_id)->toBe((string) $user->getKey())
        ->and(Str::isUuid($spoke->credential_id))->toBeTrue()
        ->and($spoke->credential_backed)->toBeFalse();

    $this->artisan('postmaster:retire-dead-spokes', ['--local' => true])->assertSuccessful();
    expect($spoke->fresh())->not->toBeNull();

    $orchestrate = [
        'team_id' => $team->id,
        'task_id' => $task->id,
        'seat_fence' => WorkOrchestratorSeat::query()->sole()->fence,
        'session_ref' => 'delegated-session',
        'action' => 'finish',
        'input' => ['state' => 'acted', 'content' => 'Finished after the spoke sweep.'],
        'idempotency_key' => 'delegated-orchestrate',
    ];
    publishDelegatedPrincipal('scalpels-user-101', $orchestrate);
    CapstanServer::tool(OrchestrateTool::class, $orchestrate)->assertOk();
});

test('a different relayed user cannot use a colliding Scalpels subject to cross a Work team boundary', function (): void {
    $alice = delegatedPrincipalUser('scalpels-user-alice');
    $bob = delegatedPrincipalUser((string) $alice->getKey(), UserRole::Admin);
    $aliceTeam = Team::query()->create(['name' => 'Alice team', 'slug' => 'alice-team']);
    $bobTeam = Team::query()->create(['name' => 'Bob team', 'slug' => 'bob-team']);

    $aliceCreate = [
        'team_id' => $aliceTeam->id,
        'idempotency_key' => 'alice-create',
        'title' => 'Alice task',
        'brief' => 'Alice only.',
    ];
    publishDelegatedPrincipal('scalpels-user-alice', $aliceCreate);
    CapstanServer::tool(CreateTaskTool::class, $aliceCreate)->assertOk();
    $aliceTask = WorkTask::query()->sole();

    $bobCreate = [
        'team_id' => $bobTeam->id,
        'idempotency_key' => 'bob-create',
        'title' => 'Bob task',
        'brief' => 'Bob owns this attribution.',
    ];
    publishDelegatedPrincipal((string) $alice->getKey(), $bobCreate);
    CapstanServer::tool(CreateTaskTool::class, $bobCreate)->assertOk();

    expect(WorkTask::query()->where('title', 'Bob task')->sole()->creator_actor)
        ->toBe((string) $bob->getKey())
        ->not->toBe((string) $alice->getKey());

    $crossTeamGet = [
        'team_id' => $bobTeam->id,
        'task_id' => $aliceTask->id,
    ];
    publishDelegatedPrincipal((string) $alice->getKey(), $crossTeamGet);
    CapstanServer::tool(GetTaskTool::class, $crossTeamGet)->assertHasErrors(['Work task is unavailable.']);

    $crossTeamQueue = [
        'team_id' => $bobTeam->id,
        'task_id' => $aliceTask->id,
        'role' => 'builder',
        'idempotency_key' => 'cross-team-queue',
    ];
    publishDelegatedPrincipal((string) $alice->getKey(), $crossTeamQueue);
    CapstanServer::tool(QueueItemTool::class, $crossTeamQueue)->assertStructuredContent([
        'outcome' => 'refused',
        'reason' => 'task_unavailable',
    ]);
});

test('an unbound delegated principal returns a readable MCP tool error with debug disabled', function (): void {
    $team = Team::query()->create(['name' => 'Unsupported team', 'slug' => 'unsupported-team']);

    $arguments = [
        'team_id' => $team->id,
        'task_id' => (string) Str::uuid7(),
    ];
    publishDelegatedPrincipal('unbound-scalpels-user', $arguments);
    CapstanServer::tool(GetTaskTool::class, $arguments)
        ->assertHasErrors(['This MCP principal is not supported.']);
});

test('a delegated principal respects the local managed user lifecycle gate', function (string $gate): void {
    $user = delegatedPrincipalUser('lifecycle-scalpels-user');

    if ($gate === 'inactive user') {
        $user->forceFill(['status' => 'inactive'])->save();
    } elseif ($gate === 'offboarded user') {
        OffboardedSubject::query()->create([
            'subject_type' => SubjectType::UserPrincipal,
            'subject_ref' => 'capstan-user:'.$user->getKey(),
            'user_id' => (string) $user->getKey(),
            'offboarded_at' => now(),
        ]);
    } elseif ($gate === 'offboarded subject') {
        OffboardedSubject::query()->create([
            'subject_type' => SubjectType::UserPrincipal,
            'subject_ref' => 'capstan-user:'.$user->getKey(),
            'user_id' => OffboardedSubject::SUBJECT_ROW,
            'offboarded_at' => now(),
        ]);
    } else {
        $user->forceFill(['managed_membership_status' => 'disabled'])->save();
        $freshnessKey = hash('sha256', implode("\0", [
            'https://scalpels.test',
            'delegated-principal-connection',
            'lifecycle-scalpels-user',
        ]));
        Cache::put('bfc:managed-refresh-attempt:'.$freshnessKey, now()->getTimestamp() + 60, 300);
    }

    publishDelegatedPrincipal('lifecycle-scalpels-user');
    CapstanServer::tool(ListPagesTool::class)
        ->assertHasErrors(['This MCP principal is not supported.']);
})->with(['inactive user', 'offboarded user', 'offboarded subject', 'disabled managed membership']);

test('a delegated member receives a readable Work authorization error with debug disabled', function (): void {
    delegatedPrincipalUser('member-scalpels-user', UserRole::Member);
    $team = Team::query()->create(['name' => 'Member team', 'slug' => 'member-team']);
    $arguments = ['team_id' => $team->id];

    publishDelegatedPrincipal('member-scalpels-user', $arguments);
    CapstanServer::tool(ListWorkTool::class, $arguments)
        ->assertHasErrors(['Only an admitted Owner or Admin may manage Work.']);
});

test('every registered read and write tool resolves a fully published delegated RequestAssertion', function (): void {
    $user = delegatedPrincipalUser('all-tools-scalpels-user');
    $team = Team::query()->create([
        'name' => 'Every delegated tool team',
        'slug' => 'every-delegated-tool-team',
        'pool_work' => true,
    ]);
    Inbox::query()->create(['actor_id' => (string) $user->getKey(), 'local_part' => 'relay']);

    $uuid = (string) Str::uuid7();
    $server = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    $arguments = [
        'ack_postmaster_messages' => ['idempotency_key' => 'all-tools-ack', 'message_ids' => ['missing-message']],
        'answer_escalation' => ['team_id' => $team->id, 'escalation_id' => $uuid, 'answer' => 'No.', 'idempotency_key' => 'all-tools-answer'],
        'apply_brand_draft' => ['idempotency_key' => 'all-tools-apply-brand', 'draft_fingerprint' => str_repeat('a', 64)],
        'cancel_item' => ['team_id' => $team->id, 'item_id' => $uuid, 'idempotency_key' => 'all-tools-cancel-item'],
        'cancel_task' => ['team_id' => $team->id, 'task_id' => $uuid, 'idempotency_key' => 'all-tools-cancel-task'],
        'create_artifact_share' => ['idempotency_key' => 'all-tools-artifact', 'content' => '<p>Delegated artifact</p>', 'content_type' => 'text/html', 'visibility' => 'org_auth'],
        'create_task' => ['team_id' => $team->id, 'title' => 'Every tool task', 'brief' => 'Exercise delegated principal resolution.', 'idempotency_key' => 'all-tools-create-task'],
        'get_brand_kit' => [],
        'get_page' => ['page_id' => $uuid],
        'get_task' => ['team_id' => $team->id, 'task_id' => $uuid],
        'list_escalations' => ['team_id' => $team->id],
        'list_layouts' => [],
        'list_pages' => [],
        'list_work' => ['team_id' => $team->id],
        'orchestrate' => ['team_id' => $team->id, 'task_id' => $uuid, 'seat_fence' => 1, 'session_ref' => 'all-tools-session', 'action' => 'finish', 'input' => ['state' => 'acted'], 'idempotency_key' => 'all-tools-orchestrate'],
        'postmaster_messages' => ['inbox' => 'relay'],
        'postmaster_spokes' => [],
        'preview_brand_draft' => [],
        'publish_page' => ['idempotency_key' => 'all-tools-publish-page', 'title' => 'Delegated page', 'recipe' => 'Rebuild from the delegated test.', 'layout' => 'report', 'content' => '<p>Delegated page</p>', 'content_type' => 'text/html'],
        'queue_item' => ['team_id' => $team->id, 'task_id' => $uuid, 'role' => 'builder', 'idempotency_key' => 'all-tools-queue'],
        'release_seat' => ['team_id' => $team->id, 'task_id' => $uuid, 'seat_fence' => 1, 'idempotency_key' => 'all-tools-release'],
        'revise_brief' => ['team_id' => $team->id, 'task_id' => $uuid, 'brief' => 'Revised.', 'idempotency_key' => 'all-tools-revise'],
        'send_postmaster_message' => ['idempotency_key' => 'all-tools-send', 'from' => 'relay@'.$server, 'to' => 'receiver@'.$server, 'type' => 'generic', 'version' => Envelope::CURRENT_VERSION, 'message_id' => 'all-tools-message', 'body' => ['subject' => 'Delegated']],
        'steer' => ['team_id' => $team->id, 'task_id' => $uuid, 'content' => 'Keep going.', 'idempotency_key' => 'all-tools-steer'],
        'take_seat' => ['team_id' => $team->id, 'task_id' => $uuid, 'session_ref' => 'all-tools-session', 'idempotency_key' => 'all-tools-take'],
        'update_brand_draft' => ['idempotency_key' => 'all-tools-update-brand', 'changes' => ['display_name' => 'Delegated brand']],
        'update_page' => ['idempotency_key' => 'all-tools-update-page', 'page_id' => $uuid, 'content' => '<p>Updated delegated page</p>', 'content_type' => 'text/html'],
        'upload_brand_asset' => ['idempotency_key' => 'all-tools-upload-brand', 'kind' => 'mark', 'bytes_base64' => base64_encode((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true))],
    ];
    ksort($arguments);

    $registered = collect(McpDelegatedTools::discover(CapstanServer::class)['tools'])
        ->mapWithKeys(fn (string $class): array => [resolve($class)->name() => $class])
        ->sortKeys();

    expect($registered->keys()->all())->toBe(array_keys($arguments));

    foreach ($registered as $name => $class) {
        $actor = publishDelegatedPrincipal('unbound-all-tools-user', $arguments[$name]);
        expect(RequestAssertion::principal(request())?->delegatedActor)->toBe($actor);
        $response = CapstanServer::tool($class, $arguments[$name]);
        try {
            $response->assertHasErrors(['This MCP principal is not supported.']);
        } catch (AssertionFailedError $error) {
            Assert::fail("The unbound delegated call to {$name} did not reach principal resolution: {$error->getMessage()}");
        }
    }

    foreach ($registered as $name => $class) {
        $actor = publishDelegatedPrincipal('all-tools-scalpels-user', $arguments[$name]);
        expect(RequestAssertion::principal(request())?->delegatedActor)->toBe($actor);
        try {
            CapstanServer::tool($class, $arguments[$name])
                ->assertDontSee('This MCP principal is not supported.');
        } catch (AssertionFailedError $error) {
            Assert::fail("The bound delegated call to {$name} failed after principal resolution: {$error->getMessage()}");
        }
    }
});
