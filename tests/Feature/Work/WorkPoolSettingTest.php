<?php

use App\Auth\CapstanCredentialDeclaration;
use App\Enums\WorkItemState;
use App\Livewire\Work\Index;
use App\Models\MachineTeamWorkSetting;
use App\Models\Spoke;
use App\Models\Team;
use App\Models\TeamWorkPolicyChange;
use App\Models\WorkItem;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'capstan.features.work' => true,
        'capstan.features.postmaster' => false,
        'capstan.work.lease_seconds' => 300,
    ]);
    Feature::flushCache();
});

function poolTeam(bool $poolWork = false): Team
{
    return Team::query()->create(['name' => 'Pool team '.Str::random(8), 'slug' => Str::random(16), 'pool_work' => $poolWork]);
}

function poolMcpToken(User $user): string
{
    $token = Str::random(40);
    Credential::query()->create([
        'kind' => CredentialKind::Bearer, 'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::UserPrincipal, 'subject_ref' => 'capstan-user:'.$user->id,
        'user_id' => (string) $user->id, 'name' => 'Pool test', 'abilities' => [],
        'secret_hash' => hash('sha256', $token), 'status' => CredentialStatus::Active,
    ]);

    return $token;
}

function poolMcpCall(string $tool, array $arguments, string $token): TestResponse
{
    return test()->postJson('/mcp/write', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $arguments],
    ], ['Authorization' => 'Bearer '.$token]);
}

function poolSet(Team $team, bool $poolWork, string $token, ?string $key = null): TestResponse
{
    return poolMcpCall('set_team_work_policy', [
        'team_id' => $team->id, 'pool_work' => $poolWork, 'idempotency_key' => $key ?? (string) Str::uuid7(),
    ], $token);
}

/** Creates a task through the real create_task tool and queues one builder item on it. */
function poolCreateQueuedItem(Team $team, string $token, array $attributes = []): string
{
    $task = poolMcpCall('create_task', $attributes + [
        'team_id' => $team->id, 'idempotency_key' => (string) Str::uuid7(), 'title' => 'Pool task', 'brief' => 'Pool brief',
    ], $token)->assertOk()->json('result.structuredContent.task');
    expect($task)->toBeArray();

    return poolMcpCall('queue_item', [
        'team_id' => $team->id, 'task_id' => $task['id'], 'role' => 'builder', 'idempotency_key' => (string) Str::uuid7(),
    ], $token)->assertOk()->json('result.structuredContent.item.id');
}

/** Drives the drain claim endpoint as $user and returns the claimed item IDs. */
function poolDrainClaim(Team $team, User $user): array
{
    $drain = capstanBoundBearer($user, CapstanCredentialDeclaration::MACHINE);
    $spoke = Spoke::query()->create([
        'actor_id' => (string) $user->id,
        'credential_id' => $drain['credential']->id,
        'last_polled_at' => now(),
    ]);
    MachineTeamWorkSetting::query()->create([
        'spoke_id' => $spoke->id,
        'team_id' => $team->id,
        'enabled' => true,
        'roles' => ['builder'],
        'capabilities' => [],
        'role_providers' => [],
        'concurrency_ceiling' => 25,
        'updated_by_actor' => (string) $user->id,
    ]);

    return test()->withHeaders([
        'Authorization' => 'Bearer '.$drain['token'],
        CapstanCredentialDeclaration::ACTOR_HEADER => (string) $user->id,
    ])->postJson(route('api.work.claim', $team), [
        'capacity' => 25,
        'roles' => ['builder'],
        'capabilities' => [],
        'role_providers' => [],
        'current_claimed_item_count' => 0,
    ])
        ->assertOk()->json('items.*.id');
}

test('an Owner and an Admin turn pooled work on and off from the Work page and each change is audited', function (string $role): void {
    Date::setTestNow('2026-10-18 09:00:00');
    $user = capstanUser(['role' => $role]);
    $team = Team::default();
    expect($team->pool_work)->toBeFalse();

    Livewire::actingAs($user)->test(Index::class)
        ->assertSeeHtml('data-testid="work-pool-setting-off"')
        ->call('setPoolWork', true)->assertHasNoErrors()
        ->assertSeeHtml('data-testid="work-pool-setting-on"')
        ->assertDontSeeHtml('data-testid="work-pool-setting-off"');
    expect($team->fresh()->pool_work)->toBeTrue();

    Date::setTestNow('2026-10-18 09:05:00');
    Livewire::actingAs($user)->test(Index::class)->call('setPoolWork', false)->assertHasNoErrors();
    expect($team->fresh()->pool_work)->toBeFalse();

    $changes = TeamWorkPolicyChange::query()->orderBy('id')->get();
    expect($changes)->toHaveCount(2)
        ->and($changes->map(fn (TeamWorkPolicyChange $change): array => [
            $change->team_id, $change->actor, $change->surface, $change->old_pool_work, $change->new_pool_work, $change->created_at->toDateTimeString(),
        ])->all())->toBe([
            [$team->id, (string) $user->id, 'web', false, true, '2026-10-18 09:00:00'],
            [$team->id, (string) $user->id, 'web', true, false, '2026-10-18 09:05:00'],
        ]);
})->with(['owner', 'admin']);

test('a Member sees the pooled work control disabled and a forged page call is refused readably without effects', function (): void {
    $member = capstanUser(['role' => 'member']);
    $team = Team::default();

    $html = $this->actingAsVersioned($member)->get(route('work.index'))->assertOk()->content();
    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $section = $xpath->query('//*[@data-testid="work-pool-setting-off"]');
    expect($section->length)->toBe(1)
        ->and($section->item(0)->textContent)->toContain($team->name);
    $toggle = $xpath->query('.//*[@data-testid="work-pool-setting-toggle"]', $section->item(0));
    expect($toggle->length)->toBe(1)
        ->and($toggle->item(0)->hasAttribute('disabled'))->toBeTrue()
        ->and($toggle->item(0)->hasAttribute('wire:click'))->toBeFalse();

    Livewire::actingAs($member)->test(Index::class)
        ->call('setPoolWork', true)
        ->assertOk()
        ->assertHasErrors('poolWork');
    expect($team->fresh()->pool_work)->toBeFalse()
        ->and(TeamWorkPolicyChange::query()->count())->toBe(0);
});

test('managers get an operable toggle that names the team it changes', function (): void {
    $team = Team::default();
    $html = $this->actingAsVersioned(capstanUser(['role' => 'admin']))->get(route('work.index'))->assertOk()->content();
    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $section = $xpath->query('//*[@data-testid="work-pool-setting-off"]')->item(0);
    expect($section)->toBeInstanceOf(DOMElement::class)
        ->and($section->textContent)->toContain($team->name);
    $toggle = $xpath->query('.//*[@data-testid="work-pool-setting-toggle"]', $section)->item(0);
    expect($toggle->hasAttribute('disabled'))->toBeFalse()
        ->and($toggle->getAttribute('wire:click'))->toBe('setPoolWork(true)');
});

test('an Owner page call with a non-boolean value is refused readably and changes nothing', function (mixed $value): void {
    $owner = capstanUser(['role' => 'owner']);
    $team = Team::default();

    Livewire::actingAs($owner)->test(Index::class)
        ->call('setPoolWork', $value)
        ->assertOk()
        ->assertHasErrors('poolWork')
        ->assertSeeHtml('data-testid="work-pool-setting-off"');
    expect($team->fresh()->pool_work)->toBeFalse()
        ->and(TeamWorkPolicyChange::query()->count())->toBe(0);

    // A real boolean from the same component still works after a refusal.
    Livewire::actingAs($owner)->test(Index::class)
        ->call('setPoolWork', true)
        ->assertHasNoErrors();
    expect($team->fresh()->pool_work)->toBeTrue()
        ->and(TeamWorkPolicyChange::query()->sole()->new_pool_work)->toBeTrue();
})->with([
    'string false' => ['false'],
    'string true' => ['true'],
    'integer one' => [1],
    'array' => [[true]],
    'null' => [null],
]);

test('the page action still requires product admission before any change', function (): void {
    $owner = capstanUser(['role' => 'owner']);
    $team = Team::default();
    $component = Livewire::actingAs($owner)->test(Index::class);
    $owner->forceFill(['status' => 'inactive'])->save();
    $component->call('setPoolWork', true)->assertForbidden();
    expect($team->fresh()->pool_work)->toBeFalse()
        ->and(TeamWorkPolicyChange::query()->count())->toBe(0);
});

test('Owners and Admins read and set the policy over MCP with one audit row per real change', function (string $role): void {
    Date::setTestNow('2026-10-18 10:00:00');
    $user = capstanUser(['role' => $role]);
    $token = poolMcpToken($user);
    $team = poolTeam();

    poolMcpCall('get_team_work_policy', ['team_id' => $team->id], $token)->assertOk()
        ->assertJsonPath('result.structuredContent', ['policy' => ['team_id' => $team->id, 'team_name' => $team->name, 'pool_work' => false]]);

    $key = (string) Str::uuid7();
    poolSet($team, true, $token, $key)->assertOk()
        ->assertJsonPath('result.structuredContent.outcome', 'accepted')
        ->assertJsonPath('result.structuredContent.changed', true)
        ->assertJsonPath('result.structuredContent.policy', ['team_id' => $team->id, 'team_name' => $team->name, 'pool_work' => true]);
    // A replay of the same request returns the stored result and records nothing new.
    poolSet($team, true, $token, $key)->assertOk()->assertJsonPath('result.structuredContent.outcome', 'already_accepted');
    // Setting the value it already has is accepted but is not a change.
    poolSet($team, true, $token)->assertOk()
        ->assertJsonPath('result.structuredContent.outcome', 'accepted')
        ->assertJsonPath('result.structuredContent.changed', false);

    poolMcpCall('get_team_work_policy', ['team_id' => $team->id], $token)->assertOk()
        ->assertJsonPath('result.structuredContent.policy.pool_work', true);
    $change = TeamWorkPolicyChange::query()->sole();
    expect([$change->team_id, $change->actor, $change->surface, $change->old_pool_work, $change->new_pool_work, $change->created_at->toDateTimeString()])
        ->toBe([$team->id, (string) $user->id, 'mcp', false, true, '2026-10-18 10:00:00']);
})->with(['owner', 'admin']);

test('a Member is refused readably over MCP for both the read and the write without effects', function (): void {
    $member = capstanUser(['role' => 'member']);
    $token = poolMcpToken($member);
    $team = poolTeam();
    $claims = DB::table('mcp_write_claims')->count();

    foreach ([
        poolSet($team, true, $token),
        poolMcpCall('get_team_work_policy', ['team_id' => $team->id], $token),
    ] as $response) {
        $response->assertOk()->assertJsonPath('result.isError', true);
        expect($response->json('result.structuredContent'))->toBeNull()
            ->and($response->json('result.content.0.text'))->toBeString()->not->toBe('');
    }
    expect($team->fresh()->pool_work)->toBeFalse()
        ->and(TeamWorkPolicyChange::query()->count())->toBe(0)
        ->and(DB::table('mcp_write_claims')->count())->toBe($claims);
});

test('the MCP write rejects a non-boolean value and an unknown team without effects', function (): void {
    $token = poolMcpToken(capstanUser(['role' => 'owner']));
    $team = poolTeam();

    $wrongType = poolMcpCall('set_team_work_policy', ['team_id' => $team->id, 'pool_work' => 'true', 'idempotency_key' => 'typed'], $token);
    expect($wrongType->status() !== 200 || $wrongType->json('result.isError') === true)->toBeTrue();
    poolMcpCall('set_team_work_policy', ['team_id' => $team->id + 1000, 'pool_work' => true, 'idempotency_key' => 'missing'], $token)
        ->assertOk()->assertJsonPath('result.isError', true);

    expect($team->fresh()->pool_work)->toBeFalse()
        ->and(TeamWorkPolicyChange::query()->count())->toBe(0);
});

test('with pooled work on, a service account drain claims a task another user created', function (): void {
    $creator = capstanUser(['role' => 'owner']);
    $token = poolMcpToken($creator);
    $service = capstanUser();
    $team = poolTeam();
    poolSet($team, true, $token)->assertOk()->assertJsonPath('result.structuredContent.policy.pool_work', true);

    $item = poolCreateQueuedItem($team, $token);

    expect(poolDrainClaim($team, $service))->toBe([$item])
        ->and(WorkItem::query()->findOrFail($item)->claimant_actor)->toBe((string) $service->id);
});

test('with pooled work off, a service account drain is never offered another user task', function (): void {
    $creator = capstanUser(['role' => 'owner']);
    $token = poolMcpToken($creator);
    $service = capstanUser();
    $team = poolTeam();
    poolSet($team, false, $token)->assertOk()->assertJsonPath('result.structuredContent.policy.pool_work', false);

    $item = poolCreateQueuedItem($team, $token);

    expect(poolDrainClaim($team, $service))->toBe([])
        ->and(WorkItem::query()->findOrFail($item)->state)->toBe(WorkItemState::Queued);
    // The item was claimable all along, just only by its creator.
    expect(poolDrainClaim($team, $creator))->toBe([$item]);
});

test('with pooled work off, a creator can still address a task at a service account', function (): void {
    $creator = capstanUser(['role' => 'owner']);
    $token = poolMcpToken($creator);
    $service = capstanUser();
    $team = poolTeam();

    $item = poolCreateQueuedItem($team, $token, ['user_actor_address' => (string) $service->id]);

    expect(poolDrainClaim($team, $service))->toBe([$item]);
});

test('turning pooled work on does not unpin tasks that already exist', function (): void {
    $creator = capstanUser(['role' => 'owner']);
    $token = poolMcpToken($creator);
    $service = capstanUser();
    $team = poolTeam();

    $pinned = poolCreateQueuedItem($team, $token);
    poolSet($team, true, $token)->assertOk()->assertJsonPath('result.structuredContent.changed', true);
    $pooled = poolCreateQueuedItem($team, $token);

    expect(poolDrainClaim($team, $service))->toBe([$pooled])
        ->and(WorkItem::query()->findOrFail($pinned)->state)->toBe(WorkItemState::Queued)
        ->and(WorkItem::query()->findOrFail($pinned)->workTask->user_actor_address)->toBeNull()
        ->and(WorkItem::query()->findOrFail($pinned)->workTask->owner_actor)->toBe((string) $creator->id)
        ->and(WorkItem::query()->findOrFail($pinned)->workTask->routing_pool_work)->toBeFalse();
});
