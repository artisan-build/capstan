<?php

use App\Auth\CapstanCredentialDeclaration;
use App\Enums\SpokeLiveness;
use App\Machine\MachineWorkProfiles;
use App\Machine\MachineWorkRuntime;
use App\Models\MachineTeamWorkSetting;
use App\Models\MachineUsageReport;
use App\Models\MachineWorkPresence;
use App\Models\MachineWorkSettingChange;
use App\Models\Spoke;
use App\Models\Team;
use App\Models\WorkItem;
use App\Models\WorkTask;
use App\Work\MachineWorkArbiter;
use App\Work\WorkClaimService;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    config(['capstan.features.work' => true, 'capstan.work.lease_seconds' => 300]);
    Date::setTestNow('2026-10-20 12:00:00');
});

/** @return array{user: User, credential: Credential, token: string, spoke: Spoke, team: Team, setting: MachineTeamWorkSetting} */
function routingMachine(array $profile = [], array $spoke = [], ?Team $team = null, ?User $user = null): array
{
    $team ??= Team::default();
    $user ??= capstanUser();
    $issued = capstanBoundBearer($user, CapstanCredentialDeclaration::MACHINE);
    $machine = Spoke::query()->create(array_replace([
        'actor_id' => (string) $user->id,
        'credential_id' => $issued['credential']->id,
        'credential_backed' => true,
        'last_polled_at' => now(),
        'probe_status' => SpokeLiveness::Unknown,
    ], $spoke));
    $setting = MachineTeamWorkSetting::query()->create(array_replace([
        'spoke_id' => $machine->id,
        'team_id' => $team->id,
        'enabled' => true,
        'roles' => ['builder'],
        'capabilities' => ['php'],
        'role_providers' => ['builder' => 'codex'],
        'concurrency_ceiling' => 2,
        'updated_by_actor' => (string) $user->id,
    ], $profile));

    return [...$issued, 'user' => $user, 'spoke' => $machine, 'team' => $team, 'setting' => $setting];
}

/** @return array<string, mixed> */
function routingPayload(array $overrides = []): array
{
    return array_replace([
        'capacity' => 1,
        'roles' => ['builder'],
        'capabilities' => ['php'],
        'role_providers' => ['builder' => 'codex'],
        'current_claimed_item_count' => 0,
    ], $overrides);
}

function routingItem(Team $team, array $task = []): WorkItem
{
    $team->update(['pool_work' => true]);
    $workTask = WorkTask::query()->create(array_replace([
        'team_id' => $team->id,
        'title' => 'Routing '.Str::random(8),
        'required_capabilities' => ['php'],
        'timebox_minutes' => 30,
        'creator_actor' => 'creator',
    ], $task));

    return $workTask->items()->create(['role' => 'builder']);
}

function routingClaim(array $machine, array $payload = []): TestResponse
{
    return test()->withHeaders([
        'Authorization' => 'Bearer '.$machine['token'],
        CapstanCredentialDeclaration::ACTOR_HEADER => (string) $machine['user']->id,
    ])->postJson(route('api.work.claim', $machine['team']), routingPayload($payload));
}

test('machine owners and Owners or Admins write bounded audited profiles while Members and inactive actors cannot', function (): void {
    $team = Team::default();
    $owner = capstanUser(['role' => 'member']);
    $machine = routingMachine(team: $team, user: $owner);
    $admin = capstanUser(['role' => 'admin']);
    $member = capstanUser(['role' => 'member']);
    $profiles = resolve(MachineWorkProfiles::class);
    $profile = [
        'enabled' => false,
        'roles' => ['reviewer', 'builder'],
        'capabilities' => ['git'],
        'role_providers' => ['builder' => 'codex'],
        'concurrency_ceiling' => 25,
    ];

    expect($profiles->put($machine['spoke'], $team, $owner, $profile, CarbonImmutable::now()))->toBeTrue()
        ->and($profiles->put($machine['spoke'], $team, $owner, $profile, CarbonImmutable::now()))->toBeFalse()
        ->and(MachineWorkSettingChange::query()->count())->toBe(1);

    Date::setTestNow(now()->addMinute());
    expect($profiles->put($machine['spoke'], $team, $admin, [...$profile, 'enabled' => true], CarbonImmutable::now()))->toBeTrue();
    $changes = MachineWorkSettingChange::query()->oldest('occurred_at')->get();
    expect($changes)->toHaveCount(2)
        ->and($changes[1]->old_enabled)->toBeFalse()
        ->and($changes[1]->old_roles)->toBe(['builder', 'reviewer'])
        ->and($changes[1]->new_enabled)->toBeTrue()
        ->and($changes[1]->updated_by_actor)->toBe((string) $admin->id);

    expect(fn () => $profiles->put($machine['spoke'], $team, $member, $profile, CarbonImmutable::now()))
        ->toThrow(AuthorizationException::class);
    expect(fn () => $profiles->put($machine['spoke'], $team, $admin, [...$profile, 'concurrency_ceiling' => 26], CarbonImmutable::now()))
        ->toThrow(InvalidArgumentException::class);
    $owner->forceFill(['status' => 'inactive'])->save();
    expect(fn () => $profiles->put($machine['spoke'], $team, $owner, $profile, CarbonImmutable::now()))
        ->toThrow(AuthorizationException::class)
        ->and(MachineWorkSettingChange::query()->count())->toBe(2);
});

test('profile writes require target-team membership and a same-team owner for manager creation', function (): void {
    $owner = capstanUser();
    $machine = routingMachine(user: $owner);
    $otherTeam = Team::query()->create(['name' => 'Other profile team', 'slug' => 'other-profile-team']);
    $admin = capstanUser(['role' => 'owner']);
    $foreignOwner = capstanUser();
    $foreignMachine = routingMachine(user: $foreignOwner)['spoke'];
    $sameTeamOwner = capstanUser();
    $sameTeamMachine = Spoke::query()->create([
        'actor_id' => (string) $sameTeamOwner->id,
        'credential_id' => (string) Str::uuid(),
        'credential_backed' => true,
    ]);
    $profile = [
        'enabled' => false,
        'roles' => ['reviewer'],
        'capabilities' => [],
        'role_providers' => [],
        'concurrency_ceiling' => 1,
    ];

    $profiles = resolve(MachineWorkProfiles::class);
    expect(fn () => $profiles->put($machine['spoke'], $otherTeam, $owner, $profile, CarbonImmutable::now()))
        ->toThrow(AuthorizationException::class);

    DB::table('team_user')->insert([
        ['team_id' => $otherTeam->id, 'user_id' => $owner->id, 'created_at' => now(), 'updated_at' => now()],
        ['team_id' => $otherTeam->id, 'user_id' => $admin->id, 'created_at' => now(), 'updated_at' => now()],
        ['team_id' => $otherTeam->id, 'user_id' => $sameTeamOwner->id, 'created_at' => now(), 'updated_at' => now()],
    ]);
    expect($profiles->put($machine['spoke'], $otherTeam, $owner, $profile, CarbonImmutable::now()))->toBeTrue()
        ->and($profiles->put($machine['spoke'], $otherTeam, $admin, [...$profile, 'enabled' => true], CarbonImmutable::now()))->toBeTrue()
        ->and($profiles->put($sameTeamMachine, $otherTeam, $admin, $profile, CarbonImmutable::now()))->toBeTrue();
    expect(fn () => $profiles->put($foreignMachine, $otherTeam, $admin, $profile, CarbonImmutable::now()))
        ->toThrow(AuthorizationException::class);

    expect(MachineTeamWorkSetting::query()->where('spoke_id', $machine['spoke']->id)->count())->toBe(2)
        ->and(MachineTeamWorkSetting::query()->where('spoke_id', $machine['spoke']->id)->where('team_id', $machine['team']->id)->sole()->enabled)->toBeTrue()
        ->and(MachineTeamWorkSetting::query()->where('spoke_id', $machine['spoke']->id)->where('team_id', $otherTeam->id)->sole()->enabled)->toBeTrue()
        ->and(MachineTeamWorkSetting::query()->where('spoke_id', $foreignMachine->id)->where('team_id', $otherTeam->id)->doesntExist())->toBeTrue()
        ->and(MachineWorkSettingChange::query()->where('team_id', $otherTeam->id)->count())->toBe(3);
});

test('claim requires the exact configured active machine and atomically records bounded effective presence', function (): void {
    $machine = routingMachine();
    $item = routingItem($machine['team']);

    routingClaim($machine)->assertOk()->assertJsonPath('items.0.id', $item->id);
    $presence = MachineWorkPresence::query()->sole();
    expect($presence->roles)->toBe(['builder'])
        ->and($presence->capabilities)->toBe(['php'])
        ->and($presence->role_providers)->toBe(['builder' => 'codex'])
        ->and($presence->current_claimed_item_count)->toBe(1)
        ->and($presence->concurrency_ceiling)->toBe(2)
        ->and($presence->observed_at->toISOString())->toBe('2026-10-20T12:00:00.000000Z');

    $before = [$presence->fresh()->toArray(), WorkItem::query()->where('state', 'claimed')->count()];
    routingClaim($machine, ['roles' => ['builder', 'reviewer']])
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
    expect([MachineWorkPresence::query()->sole()->toArray(), WorkItem::query()->where('state', 'claimed')->count()])->toBe($before);

    routingClaim($machine, ['role_providers' => ['reviewer' => 'claude']])
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
    routingClaim($machine, ['role_providers' => ['builder' => 'claude']])
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
    $machine['setting']->update(['role_providers' => []]);
    routingClaim($machine, ['role_providers' => ['builder' => 'codex']])
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
    expect(MachineWorkPresence::query()->count())->toBe(1);

    $foreign = capstanBoundBearer($machine['user'], CapstanCredentialDeclaration::MACHINE);
    test()->withHeaders([
        'Authorization' => 'Bearer '.$foreign['token'],
        CapstanCredentialDeclaration::ACTOR_HEADER => (string) $machine['user']->id,
    ])->postJson(route('api.work.claim', $machine['team']), routingPayload())->assertOk()->assertExactJson(['items' => []]);

    $machine['setting']->update(['enabled' => false]);
    routingClaim($machine)->assertOk()->assertExactJson(['items' => []]);
});

test('a pending machine that has never polled cannot claim or create presence', function (): void {
    $machine = routingMachine(spoke: ['last_polled_at' => null]);
    $item = routingItem($machine['team']);

    routingClaim($machine)->assertOk()->assertExactJson(['items' => []]);
    expect($item->fresh()->state->value)->toBe('queued')
        ->and(MachineWorkPresence::query()->count())->toBe(0);
});

test('authoritative claims enforce concurrency even when the machine reports zero load', function (): void {
    $machine = routingMachine(['concurrency_ceiling' => 1]);
    $first = routingItem($machine['team']);
    $second = routingItem($machine['team']);

    routingClaim($machine, ['capacity' => 25, 'current_claimed_item_count' => 0])
        ->assertOk()->assertJsonPath('items.0.id', $first->id)->assertJsonCount(1, 'items');
    routingClaim($machine, ['capacity' => 25, 'current_claimed_item_count' => 0])
        ->assertOk()->assertExactJson(['items' => []]);
    expect($second->fresh()->state->value)->toBe('queued')
        ->and(MachineWorkPresence::query()->sole()->current_claimed_item_count)->toBe(1);
});

test('a preferred capacity zero machine cannot starve a capacity positive machine', function (): void {
    $team = Team::default();
    $team->update(['pool_work' => true]);
    $preferred = routingMachine(team: $team, spoke: ['probe_status' => SpokeLiveness::Green]);
    $available = routingMachine(team: $team, spoke: ['probe_status' => SpokeLiveness::Green]);
    $item = routingItem($team);

    routingClaim($preferred, ['capacity' => 0, 'current_claimed_item_count' => 0])
        ->assertOk()->assertExactJson(['items' => []]);
    routingClaim($available, ['capacity' => 1, 'current_claimed_item_count' => 25])
        ->assertOk()->assertJsonPath('items.0.id', $item->id)->assertJsonCount(1, 'items');

    $presence = MachineWorkPresence::query()->where('spoke_id', $preferred['spoke']->id)->sole();
    expect($presence->available_capacity)->toBe(0)
        ->and($item->fresh()->claimant_spoke_id)->toBe($available['spoke']->id);
});

test('presence is replaced and expires at exactly 180 seconds', function (): void {
    $machine = routingMachine();
    $runtime = resolve(MachineWorkRuntime::class);
    $runtime->record($machine['team']->id, (string) $machine['user']->id, $machine['credential']->id, ['builder'], ['php'], [], 1, 0, CarbonImmutable::now());
    $presence = MachineWorkPresence::query()->sole();
    expect($runtime->isFresh($presence, CarbonImmutable::now()->addSeconds(179)))->toBeTrue()
        ->and($runtime->isFresh($presence, CarbonImmutable::now()->addSeconds(180)))->toBeFalse();

    Date::setTestNow(now()->addSeconds(60));
    $runtime->record($machine['team']->id, (string) $machine['user']->id, $machine['credential']->id, ['builder'], [], [], 1, 0, CarbonImmutable::now());
    expect(MachineWorkPresence::query()->count())->toBe(1)
        ->and(MachineWorkPresence::query()->sole()->capabilities)->toBe([])
        ->and(MachineWorkPresence::query()->sole()->observed_at->toISOString())->toBe('2026-10-20T12:01:00.000000Z');
});

test('both candidate discovery and the guarded write reject a disabled profile', function (): void {
    $machine = routingMachine();
    routingItem($machine['team']);
    resolve(MachineWorkRuntime::class)->record(
        $machine['team']->id, (string) $machine['user']->id, $machine['credential']->id,
        ['builder'], ['php'], ['builder' => 'codex'], 1, 0, CarbonImmutable::now(),
    );
    $claims = resolve(WorkClaimService::class);

    $machine['setting']->update(['enabled' => false]);
    expect($claims->claim($machine['team']->id, (string) $machine['user']->id, $machine['spoke']->id, ['builder'], ['php'], 1, CarbonImmutable::now(), 300, ['builder' => 'codex'], true))->toBe([]);

    $machine['setting']->update(['enabled' => true]);
    expect($claims->claim(
        $machine['team']->id, (string) $machine['user']->id, $machine['spoke']->id,
        ['builder'], ['php'], 1, CarbonImmutable::now(), 300, ['builder' => 'codex'], true,
        fn () => $machine['setting']->update(['enabled' => false]),
    ))->toBe([])
        ->and(WorkItem::query()->where('state', 'claimed')->count())->toBe(0);
});

/** @param array<string, mixed> $attributes */
function routingPresence(array $machine, array $attributes = []): MachineWorkPresence
{
    return MachineWorkPresence::query()->create(array_replace([
        'spoke_id' => $machine['spoke']->id,
        'team_id' => $machine['team']->id,
        'roles' => ['builder'],
        'capabilities' => ['php'],
        'role_providers' => ['builder' => 'codex'],
        'current_claimed_item_count' => 0,
        'available_capacity' => 1,
        'concurrency_ceiling' => $machine['setting']->concurrency_ceiling,
        'observed_at' => now(),
    ], $attributes));
}

function routingUsage(array $machine, float $remaining, ?string $reset = '2026-10-20 13:00:00', string $provider = 'codex', ?string $received = null): void
{
    $report = MachineUsageReport::query()->create([
        'spoke_id' => $machine['spoke']->id,
        'source' => 'openusage-app',
        'availability' => 'available',
        'collected_at' => now(),
        'last_attempt_at' => now(),
        'last_success_received_at' => $received ?? now(),
    ]);
    $usage = $report->providers()->create([
        'provider' => $provider,
        'fetched_at' => now(),
        'source_stale' => false,
        'expires_at' => now()->addMinutes(15),
        'status' => 'ok',
    ]);
    $usage->windows()->create([
        'slot' => 'primary',
        'kind' => 'consumption',
        'window_seconds' => 18000,
        'remaining_percent' => $remaining,
        'resets_at' => $reset,
    ]);
}

test('pooled arbitration follows health relevant usage headroom reset load and stable id ordering', function (): void {
    $team = Team::default();
    $team->update(['pool_work' => true]);
    $first = routingMachine(team: $team, spoke: ['probe_status' => SpokeLiveness::Unknown]);
    $second = routingMachine(team: $team, spoke: ['probe_status' => SpokeLiveness::Green]);
    routingPresence($first);
    routingPresence($second);
    $arbiter = resolve(MachineWorkArbiter::class);

    expect($arbiter->isWinner($team->id, 'builder', ['php'], $second['spoke']->id, CarbonImmutable::now()))->toBeTrue();

    $first['spoke']->update(['probe_status' => SpokeLiveness::Green]);
    routingUsage($first, 80, provider: 'claude');
    routingUsage($second, 20);
    expect($arbiter->isWinner($team->id, 'builder', ['php'], $second['spoke']->id, CarbonImmutable::now()))->toBeTrue();

    $first['spoke']->usageReport()->delete();
    routingUsage($first, 80);
    $first['spoke']->refresh()->usageReport->providers->first()->windows()->create([
        'slot' => 'secondary',
        'kind' => 'consumption',
        'window_seconds' => 604800,
        'remaining_percent' => 10,
        'resets_at' => '2026-10-20 14:00:00',
    ]);
    expect($arbiter->isWinner($team->id, 'builder', ['php'], $second['spoke']->id, CarbonImmutable::now()))->toBeTrue();
    $first['spoke']->refresh()->usageReport->providers->first()->windows()->where('slot', 'secondary')->delete();
    expect($arbiter->isWinner($team->id, 'builder', ['php'], $first['spoke']->id, CarbonImmutable::now()))->toBeTrue();

    $first['spoke']->usageReport->providers->first()->windows()->update(['remaining_percent' => 20, 'resets_at' => '2026-10-20 12:30:00']);
    expect($arbiter->isWinner($team->id, 'builder', ['php'], $first['spoke']->id, CarbonImmutable::now()))->toBeTrue();

    $first['spoke']->usageReport->providers->first()->windows()->update(['resets_at' => '2026-10-20 13:00:00']);
    $load = routingItem($team, ['required_capabilities' => []]);
    $load->update(['state' => 'claimed', 'claimant_spoke_id' => $first['spoke']->id, 'claimant_actor' => (string) $first['user']->id, 'lease_until' => now()->addMinute(), 'claimed_at' => now()]);
    expect($arbiter->isWinner($team->id, 'builder', ['php'], $second['spoke']->id, CarbonImmutable::now()))->toBeTrue();

    $load->update(['state' => 'acted']);
    expect($arbiter->isWinner($team->id, 'builder', ['php'], $first['spoke']->id, CarbonImmutable::now()))->toBeTrue();

    $first['spoke']->update(['probe_status' => SpokeLiveness::Red]);
    expect($arbiter->isWinner($team->id, 'builder', ['php'], $second['spoke']->id, CarbonImmutable::now()))->toBeTrue();
    $second['spoke']->update(['inactive_at' => now()]);
    expect($arbiter->isWinner($team->id, 'builder', ['php'], $second['spoke']->id, CarbonImmutable::now()))->toBeFalse();
});

test('unknown and stale usage remain eligible and expired presence stops blocking', function (): void {
    $team = Team::default();
    $team->update(['pool_work' => true]);
    $older = routingMachine(team: $team, spoke: ['probe_status' => SpokeLiveness::Green]);
    $newer = routingMachine(team: $team, spoke: ['probe_status' => SpokeLiveness::Green]);
    routingPresence($older, ['observed_at' => now()->subSeconds(180)]);
    routingPresence($newer);
    routingUsage($newer, 90, received: '2026-10-20 11:49:59');

    expect(resolve(MachineWorkArbiter::class)->isWinner($team->id, 'builder', ['php'], $newer['spoke']->id, CarbonImmutable::now()))->toBeTrue();
});

test('an explicit device pin remains authoritative over pooled ranking', function (): void {
    $team = Team::default();
    $team->update(['pool_work' => true]);
    $pinned = routingMachine(team: $team, spoke: ['probe_status' => SpokeLiveness::Unknown]);
    $preferred = routingMachine(team: $team, spoke: ['probe_status' => SpokeLiveness::Green]);
    routingPresence($pinned);
    routingPresence($preferred);
    $item = routingItem($team, ['device_spoke_id' => $pinned['spoke']->id, 'failover' => false]);

    routingClaim($pinned)->assertOk()->assertJsonPath('items.0.id', $item->id);
    expect($item->fresh()->claimant_spoke_id)->toBe($pinned['spoke']->id);
});
