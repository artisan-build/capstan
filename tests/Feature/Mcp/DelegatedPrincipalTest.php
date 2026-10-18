<?php

use App\Enums\PageLayout;
use App\Mcp\CapstanServer;
use App\Mcp\Tools\AckPostmasterMessagesTool;
use App\Mcp\Tools\AnswerEscalationTool;
use App\Mcp\Tools\ApplyBrandDraftTool;
use App\Mcp\Tools\CancelItemTool;
use App\Mcp\Tools\CancelTaskTool;
use App\Mcp\Tools\CreateArtifactShareTool;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\GetBrandKitTool;
use App\Mcp\Tools\GetPageTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\GetTeamWorkPolicyTool;
use App\Mcp\Tools\ListEscalationsTool;
use App\Mcp\Tools\ListLayoutsTool;
use App\Mcp\Tools\ListPagesTool;
use App\Mcp\Tools\ListWorkTool;
use App\Mcp\Tools\OrchestrateTool;
use App\Mcp\Tools\PostmasterMessagesTool;
use App\Mcp\Tools\PostmasterSpokesTool;
use App\Mcp\Tools\PreviewBrandDraftTool;
use App\Mcp\Tools\PublishPageTool;
use App\Mcp\Tools\QueueItemTool;
use App\Mcp\Tools\ReleaseSeatTool;
use App\Mcp\Tools\ReviseBriefTool;
use App\Mcp\Tools\SendPostmasterMessageTool;
use App\Mcp\Tools\SetTeamWorkPolicyTool;
use App\Mcp\Tools\SteerTool;
use App\Mcp\Tools\TakeSeatTool;
use App\Mcp\Tools\UpdateBrandDraftTool;
use App\Mcp\Tools\UpdatePageTool;
use App\Mcp\Tools\UploadBrandAssetTool;
use App\Models\Envelope;
use App\Models\Inbox;
use App\Models\Page;
use App\Models\Spoke;
use App\Models\Team;
use App\Models\WorkOrchestratorSeat;
use App\Models\WorkTask;
use App\Support\BrandKitService;
use App\Work\WorkOrchestrationMcpService;
use App\Work\WorkToolService;
use ArtisanBuild\BuiltForCloud\AuthorityMode;
use ArtisanBuild\BuiltForCloud\Commands\ConsoleReKeyCommand;
use ArtisanBuild\BuiltForCloud\Commands\ConsoleRetireKeyCommand;
use ArtisanBuild\BuiltForCloud\Commands\CreateAdminCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialActivateCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialListCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialMintCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialRevokeCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialRotateCommand;
use ArtisanBuild\BuiltForCloud\Commands\FreshCommand;
use ArtisanBuild\BuiltForCloud\Commands\HmacRewrapCommand;
use ArtisanBuild\BuiltForCloud\Commands\InstallOperatorCredentialCommand;
use ArtisanBuild\BuiltForCloud\Commands\OutboxDrainCommand;
use ArtisanBuild\BuiltForCloud\Commands\OwnershipMintClaimCommand;
use ArtisanBuild\BuiltForCloud\Commands\OwnershipRemintOwnerTokenCommand;
use ArtisanBuild\BuiltForCloud\Commands\PruneCredentialAuthorizationsCommand;
use ArtisanBuild\BuiltForCloud\Commands\SigningRootEnsureCommand;
use ArtisanBuild\BuiltForCloud\Commands\SigningRootProvisionCommand;
use ArtisanBuild\BuiltForCloud\Commands\SubjectOffboardCommand;
use ArtisanBuild\BuiltForCloud\Commands\WarnExpiringCredentialsCommand;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipal;
use ArtisanBuild\BuiltForCloud\Console\Assertion;
use ArtisanBuild\BuiltForCloud\Console\AssertionPurpose;
use ArtisanBuild\BuiltForCloud\Console\ConsoleRole;
use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
use ArtisanBuild\BuiltForCloud\Console\RequestAssertion;
use ArtisanBuild\BuiltForCloud\Hmac\SigningRootLifecycle;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\Jobs\DeliverOwnershipWebhook;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RequestEffectCeiling;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\ConsumerConformance;
use ArtisanBuild\BuiltForCloud\Testing\ContractAssertions;
use ArtisanBuild\BuiltForCloud\Testing\FleetConformance;
use ArtisanBuild\BuiltForCloud\Testing\McpDelegatedEnforcement;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Mcp\ResponseFactory;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Assert;
use Ramsey\Uuid\Uuid;

uses(ContractAssertions::class);

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

function delegatedPrincipalUser(
    string $scalpelsId,
    UserRole $role = UserRole::Owner,
    string $issuer = 'https://scalpels.test',
    string $connectionId = 'delegated-principal-connection',
): User {
    return capstanUser([
        'role' => $role->value,
        'scalpels_issuer' => $issuer,
        'scalpels_connection_id' => $connectionId,
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

test('the delegated enforcement harness reaches every tool through the canonical principal resolver', function (): void {
    DB::table('bfc_authority')->where('key', InstallationAuthority::KEY)->update([
        'issuer' => 'https://bfc-enforcement.test',
        'authority_base_url' => 'https://bfc-enforcement.test',
    ]);
    $connectionId = 'delegated-principal-connection';
    $user = delegatedPrincipalUser(
        'bfc-enforcement-primary',
        issuer: 'https://bfc-enforcement.test',
        connectionId: $connectionId,
    );
    delegatedPrincipalUser(
        'bfc-enforcement-secondary',
        UserRole::Admin,
        issuer: 'https://bfc-enforcement.test',
        connectionId: $connectionId,
    );
    $team = Team::query()->create([
        'name' => 'Every delegated tool team',
        'slug' => 'every-delegated-tool-team',
        'pool_work' => true,
    ]);
    Inbox::query()->create(['actor_id' => (string) $user->getKey(), 'local_part' => 'relay']);
    resolve(BrandKitService::class)->updateDraft($user, ['display_name' => 'Delegated brand']);
    $orchestrationTask = resolve(WorkToolService::class)->write('create_task', [
        'team_id' => $team->id,
        'title' => 'Delegated enforcement orchestration task',
        'brief' => 'Used to reach the orchestration handler.',
        'failover' => true,
        'required_capabilities' => [],
        'timebox_minutes' => 30,
    ], (string) $user->getKey());
    $enforcementIdentityHash = DelegatedActor::identityHash(
        'https://bfc-enforcement.test',
        'bfc-enforcement-primary',
    );
    $enforcementCredentialId = (string) Uuid::uuid5(
        Uuid::NAMESPACE_URL,
        DelegatedActor::class."\0".$enforcementIdentityHash,
    );
    $seat = resolve(WorkOrchestrationMcpService::class)->take(
        $team->id,
        $orchestrationTask['task']['id'],
        (string) $user->getKey(),
        $enforcementCredentialId,
        null,
        'all-tools-session',
        0,
        CarbonImmutable::now(),
        credentialBacked: false,
    );
    $uuid = (string) Str::uuid7();
    $server = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    $arguments = [
        AckPostmasterMessagesTool::class => ['idempotency_key' => 'all-tools-ack', 'message_ids' => ['missing-message']],
        AnswerEscalationTool::class => ['team_id' => $team->id, 'escalation_id' => $uuid, 'answer' => 'No.', 'idempotency_key' => 'all-tools-answer'],
        ApplyBrandDraftTool::class => ['idempotency_key' => 'all-tools-apply-brand', 'draft_fingerprint' => str_repeat('a', 64)],
        CancelItemTool::class => ['team_id' => $team->id, 'item_id' => $uuid, 'idempotency_key' => 'all-tools-cancel-item'],
        CancelTaskTool::class => ['team_id' => $team->id, 'task_id' => $uuid, 'idempotency_key' => 'all-tools-cancel-task'],
        CreateArtifactShareTool::class => ['idempotency_key' => 'all-tools-artifact', 'content' => '<p>Delegated artifact</p>', 'content_type' => 'text/html', 'visibility' => 'org_auth'],
        CreateTaskTool::class => ['team_id' => $team->id, 'title' => 'Every tool task', 'brief' => 'Exercise delegated principal resolution.', 'idempotency_key' => 'all-tools-create-task'],
        GetBrandKitTool::class => [],
        GetPageTool::class => ['page_id' => $uuid],
        GetTaskTool::class => ['team_id' => $team->id, 'task_id' => $uuid],
        GetTeamWorkPolicyTool::class => ['team_id' => $team->id],
        ListEscalationsTool::class => ['team_id' => $team->id],
        ListLayoutsTool::class => [],
        ListPagesTool::class => [],
        ListWorkTool::class => ['team_id' => $team->id],
        OrchestrateTool::class => ['team_id' => $team->id, 'task_id' => $orchestrationTask['task']['id'], 'seat_fence' => $seat['seat']['seat_fence'], 'session_ref' => 'all-tools-session', 'action' => 'create_child', 'input' => ['under_task_id' => $orchestrationTask['task']['id'], 'title' => 'Harness child', 'brief' => 'Created by delegated enforcement.'], 'idempotency_key' => 'all-tools-orchestrate'],
        PostmasterMessagesTool::class => ['inbox' => 'relay'],
        PostmasterSpokesTool::class => [],
        PreviewBrandDraftTool::class => [],
        PublishPageTool::class => ['idempotency_key' => 'all-tools-publish-page', 'title' => 'Delegated page', 'recipe' => 'Rebuild from the delegated test.', 'layout' => 'report', 'content' => '<p>Delegated page</p>', 'content_type' => 'text/html'],
        QueueItemTool::class => ['team_id' => $team->id, 'task_id' => $uuid, 'role' => 'builder', 'idempotency_key' => 'all-tools-queue'],
        ReleaseSeatTool::class => ['team_id' => $team->id, 'task_id' => $uuid, 'seat_fence' => 1, 'idempotency_key' => 'all-tools-release'],
        ReviseBriefTool::class => ['team_id' => $team->id, 'task_id' => $uuid, 'brief' => 'Revised.', 'idempotency_key' => 'all-tools-revise'],
        SendPostmasterMessageTool::class => ['idempotency_key' => 'all-tools-send', 'from' => 'relay@'.$server, 'to' => 'receiver@'.$server, 'type' => 'generic', 'version' => Envelope::CURRENT_VERSION, 'message_id' => 'all-tools-message', 'body' => ['subject' => 'Delegated']],
        SetTeamWorkPolicyTool::class => ['team_id' => $team->id, 'pool_work' => true, 'idempotency_key' => 'all-tools-pool-work'],
        SteerTool::class => ['team_id' => $team->id, 'task_id' => $uuid, 'content' => 'Keep going.', 'idempotency_key' => 'all-tools-steer'],
        TakeSeatTool::class => ['team_id' => $team->id, 'task_id' => $uuid, 'session_ref' => 'all-tools-session', 'idempotency_key' => 'all-tools-take'],
        UpdateBrandDraftTool::class => ['idempotency_key' => 'all-tools-update-brand', 'changes' => ['display_name' => 'Delegated brand']],
        UpdatePageTool::class => ['idempotency_key' => 'all-tools-update-page', 'page_id' => $uuid, 'content' => '<p>Updated delegated page</p>', 'content_type' => 'text/html'],
        UploadBrandAssetTool::class => ['idempotency_key' => 'all-tools-upload-brand', 'kind' => 'mark', 'bytes_base64' => base64_encode((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true))],
    ];
    $expected = [
        CreateTaskTool::class,
        ReviseBriefTool::class,
        QueueItemTool::class,
        CancelItemTool::class,
        ListWorkTool::class,
        GetTaskTool::class,
        SteerTool::class,
        CancelTaskTool::class,
        GetTeamWorkPolicyTool::class,
        SetTeamWorkPolicyTool::class,
        TakeSeatTool::class,
        ReleaseSeatTool::class,
        ListEscalationsTool::class,
        AnswerEscalationTool::class,
        OrchestrateTool::class,
        PostmasterSpokesTool::class,
        PostmasterMessagesTool::class,
        SendPostmasterMessageTool::class,
        AckPostmasterMessagesTool::class,
        CreateArtifactShareTool::class,
        PublishPageTool::class,
        UpdatePageTool::class,
        ListPagesTool::class,
        GetPageTool::class,
        GetBrandKitTool::class,
        ListLayoutsTool::class,
        PreviewBrandDraftTool::class,
        UpdateBrandDraftTool::class,
        UploadBrandAssetTool::class,
        ApplyBrandDraftTool::class,
    ];
    $negativeAuthorization = static function (
        ActingPrincipal $first,
        ActingPrincipal $second,
        Closure $invoke,
    ): void {
        $firstUser = User::query()
            ->where('scalpels_issuer', 'https://bfc-enforcement.test')
            ->where('scalpels_connection_id', 'delegated-principal-connection')
            ->where('scalpels_id', $first->delegatedActor?->subject)
            ->sole();
        $secondUser = User::query()
            ->where('scalpels_issuer', 'https://bfc-enforcement.test')
            ->where('scalpels_connection_id', 'delegated-principal-connection')
            ->where('scalpels_id', $second->delegatedActor?->subject)
            ->sole();
        $page = Page::query()->create([
            'owner_user_id' => (string) $firstUser->getKey(),
            'title' => 'Primary enforcement subject only',
            'refresh_recipe' => 'Created by the delegated enforcement isolation case.',
            'layout' => PageLayout::Report,
        ]);

        $response = $invoke(ListPagesTool::class);

        Assert::assertInstanceOf(ResponseFactory::class, $response);
        $structured = $response->getStructuredContent();
        Assert::assertIsArray($structured);
        Assert::assertNotSame((string) $firstUser->getKey(), (string) $secondUser->getKey());
        Assert::assertNotContains($page->id, array_column($structured['items'] ?? [], 'id'));
    };

    McpDelegatedEnforcement::assertHonors(
        CapstanServer::class,
        $expected,
        ConsoleRole::Admin,
        $negativeAuthorization,
        $arguments,
    );
    $probe = McpDelegatedEnforcement::probe(
        CapstanServer::class,
        ConsoleRole::Admin,
        $negativeAuthorization,
        $arguments,
    );
    $sortedExpected = $expected;
    sort($sortedExpected);

    expect($probe['tools'])->toBe($sortedExpected)
        ->and($probe['invoked'])->toBe($sortedExpected)
        ->and($probe['refusals'])->toBe([])
        ->and($probe['principal_mismatches'])->toBe([])
        ->and($probe['unreached'])->toBe([])
        ->and($probe['negative_authorization'])->toBe('passed')
        ->and($probe['violations'])->toBe([]);

    $spec = new ConsumerConformance(
        consumer: 'capstan',
        consumerRoot: base_path(),
        packageRoot: base_path('vendor/artisan-build/built-for-cloud'),
        sourceRoots: [base_path('app/Mcp')],
        providerFiles: [
            base_path('vendor/artisan-build/built-for-cloud/src/BuiltForCloudServiceProvider.php'),
        ],
        runtimeAssertions: ['meta'],
        capabilities: ['mcp-delegated'],
        purposeMappings: [],
        mcpServer: CapstanServer::class,
        expected: [
            'runtime.meta' => [],
            'runtime.auth_schema' => [],
            'runtime.credential_listing' => [],
            'runtime.transport_parity' => [],
            'thin_host' => [],
            'credential_paths' => [
                'path:Basic|ArtisanBuild\BuiltForCloud\Auth\BasicAuthenticator',
                'path:Bearer|ArtisanBuild\BuiltForCloud\Auth\BearerAuthenticator',
                'path:HMAC|Http\Middleware\VerifyHmacSignature+Hmac\HmacVerifier',
                'path:MCP|Http\Middleware\AuthenticateMcp:store-bearer+v4.public',
                'path:asymmetric|Actions\MintCredential::mintEnrollment+CompleteAsymmetricEnrollment+AsymmetricVerificationKeys',
                'path:device|Http\Controllers\DeviceAuthorizations+Actions\StartDeviceAuthorization/DecideDeviceAuthorization/PollDeviceAuthorization+BoundBearerCredentialAuthenticator+ContainCredentialAuthorizations',
                'path:enrollment|OnboardingToken+POST:/bfc/claim,/bfc/onboarding/issue,/exchange,/verify',
                'path:loopback|Http\Controllers\LoopbackAuthorizations+Actions\StartLoopbackAuthorization/DecideLoopbackAuthorization/ExchangeLoopbackAuthorization+BoundBearerCredentialAuthenticator+ContainCredentialAuthorizations',
                'path:system|SubjectType::Operator/Application/Installation+AuditActorType::CliOperator',
            ],
            'credential_writers' => [
                'ArtisanBuild\BuiltForCloud\Actions\MintCredential::mintEnrollment',
                'ArtisanBuild\BuiltForCloud\Actions\MintCredential::mintSecretBearing',
                'ArtisanBuild\BuiltForCloud\Actions\MintCredential::mintSigningKey',
                'ArtisanBuild\BuiltForCloud\Actions\RotateCredential::replaceWithEnrollment',
                'ArtisanBuild\BuiltForCloud\Actions\RotateCredential::replaceWithPendingSigningKey',
                'ArtisanBuild\BuiltForCloud\Actions\RotateCredential::replaceWithSecret',
                'ArtisanBuild\BuiltForCloud\OwnerCredentialMinter::mintFromHash',
                'ArtisanBuild\BuiltForCloud\UnifiedStoreCredentialMinter::mint',
            ],
            'legacy_removal' => [],
            'system_authority' => [
                ConsoleReKeyCommand::class,
                ConsoleRetireKeyCommand::class,
                CreateAdminCommand::class,
                CredentialActivateCommand::class,
                CredentialListCommand::class,
                CredentialMintCommand::class,
                CredentialRevokeCommand::class,
                CredentialRotateCommand::class,
                FreshCommand::class,
                HmacRewrapCommand::class,
                InstallOperatorCredentialCommand::class,
                OutboxDrainCommand::class,
                OwnershipMintClaimCommand::class,
                OwnershipRemintOwnerTokenCommand::class,
                PruneCredentialAuthorizationsCommand::class,
                SigningRootEnsureCommand::class,
                SigningRootProvisionCommand::class,
                SubjectOffboardCommand::class,
                WarnExpiringCredentialsCommand::class,
                DeliverOwnershipWebhook::class,
                'Closure@package/src/SystemAuthoritySchedule.php:27',
            ],
            'no_signing_path' => [],
            'ui_config_reads' => [
                'ArtisanBuild\BuiltForCloud\AppPurposeRegistry|built-for-cloud.credentials.app_purposes|1',
                'ArtisanBuild\BuiltForCloud\Http\Controllers\ManageTransitions|built-for-cloud.ui.managed_transitions|1',
                'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.installation_credentials|1',
                'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.managed_transitions|1',
                'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.member_management|1',
                'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.personal_credentials|1',
                'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.session_management|1',
                'ArtisanBuild\BuiltForCloud\LandingManifest|built-for-cloud.manifest|1',
                'ArtisanBuild\BuiltForCloud\Mcp\TwoPhaseConfirmationStore|built-for-cloud.manifest.slug|1',
                'ArtisanBuild\BuiltForCloud\UiCredentialPurposes|built-for-cloud.ui.credential_purposes|1',
            ],
            'mcp_delegated' => $sortedExpected,
        ],
    );
    $report = (new FleetConformance($this))->inspect($spec);
    $delegated = $report->families['mcp_delegated'];

    expect($delegated->applicability)->toBe('applicable')
        ->and($delegated->visited)->toBe(30)
        ->and($delegated->expected)->toBe($sortedExpected)
        ->and($delegated->discovered)->toBe($sortedExpected)
        ->and($delegated->violations)->toBe([]);

    foreach ($report->families as $family => $result) {
        if ($family !== 'system_authority') {
            expect($result->violations)->toBe([]);
        }
    }

    $scheduledCommands = [
        'postmaster:probe-sweep',
        'postmaster:retire-dead-spokes --local',
        'work:failover-sweep',
        'work:liveness-sweep',
        'work:orchestration-sweep',
    ];
    $artisan = escapeshellarg(PHP_BINARY).' '.escapeshellarg('artisan').' ';
    $expectedSystemAuthorityViolations = [];
    foreach ($scheduledCommands as $command) {
        $expectedSystemAuthorityViolations[] = 'unexpected:'.$artisan.$command;
        $expectedSystemAuthorityViolations[] = 'uninspectable-schedule:'.$artisan.$command;
    }
    sort($expectedSystemAuthorityViolations);

    $systemAuthority = $report->families['system_authority'];
    expect($report->passed)->toBeFalse()
        ->and($systemAuthority->applicability)->toBe('applicable')
        ->and($systemAuthority->violations)->toBe($expectedSystemAuthorityViolations);
});
