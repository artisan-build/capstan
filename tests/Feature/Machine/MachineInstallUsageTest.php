<?php

use App\Auth\CapstanCredentialDeclaration;
use App\Http\Controllers\Api\ReplacePostmasterSpokeController;
use App\Machine\MachineUsageFreshness;
use App\Machine\MachineWorkRoleRegistry;
use App\Models\Inbox;
use App\Models\MachineTeamWorkSetting;
use App\Models\MachineUsageProvider;
use App\Models\MachineUsageReport;
use App\Models\Spoke;
use App\Models\Team;
use App\Postmaster\PendingInstallLinks;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('u', 32)),
        'app.name' => 'Capstan',
        'app.url' => 'https://capstan.example',
        'capstan.features.postmaster' => true,
        'capstan.features.work' => true,
        'capstan.postmaster.server_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
    ]);
    Feature::flushCache();
});

/** @param array{token: string, credential: Credential} $issued */
function bindPendingMachineInstall(object $test, User $owner, array $issued): object
{
    $existingIds = DB::table('postmaster_installs')->pluck('id')->all();
    $test->actingAs($owner);
    $request = request();
    $request->setUserResolver(static fn (): User => $owner);
    $request->setLaravelSession(resolve('session')->driver());
    resolve(PendingInstallLinks::class)->issue($request, (string) $owner->getKey());
    $install = DB::table('postmaster_installs')->whereNotIn('id', $existingIds)->sole();
    $now = now();
    DB::table('credential_authorizations')->where('id', $install->credential_authorization_id)->update([
        'status' => 'consumed',
        'consumed_at' => $now,
        'issued_credential_id' => $issued['credential']->id,
        'updated_at' => $now,
    ]);
    DB::table('postmaster_installs')->where('id', $install->id)->update([
        'used_at' => $now,
        'updated_at' => $now,
    ]);

    return DB::table('postmaster_installs')->where('id', $install->id)->firstOrFail();
}

/** @param array{token: string, credential: Credential} $issued @return array<string, string> */
function machineUsageHeaders(User $owner, array $issued): array
{
    return [
        'Authorization' => 'Bearer '.$issued['token'],
        CapstanCredentialDeclaration::ACTOR_HEADER => (string) $owner->getKey(),
    ];
}

/** @return array<string, mixed> */
function validMachineUsage(string $provider = 'codex', float $remaining = 72.5): array
{
    return [
        'schema_version' => 1,
        'availability' => 'available',
        'source' => 'openusage-app',
        'source_version' => null,
        'collected_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        'providers' => [[
            'provider' => $provider,
            'plan' => 'team',
            'fetched_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'source_stale' => false,
            'expires_at' => now()->addMinutes(5)->utc()->format('Y-m-d\TH:i:s\Z'),
            'status' => 'ok',
            'windows' => [[
                'slot' => 'primary',
                'kind' => 'consumption',
                'window_seconds' => 18000,
                'remaining_percent' => $remaining,
                'resets_at' => now()->addHour()->utc()->format('Y-m-d\TH:i:s\Z'),
            ]],
        ]],
    ];
}

test('the installer stores bounded defaults and first poll applies only the server-held profile once', function (): void {
    $owner = capstanUser();
    $issued = capstanBoundBearer($owner, CapstanCredentialDeclaration::MACHINE);
    $install = bindPendingMachineInstall($this, $owner, $issued);
    $roles = resolve(MachineWorkRoleRegistry::class)->all();

    expect($install->team_id)->toBe(Team::default()->id)
        ->and((bool) $install->work_enabled)->toBeTrue()
        ->and(json_decode($install->work_roles, true, flags: JSON_THROW_ON_ERROR))->toBe($roles)
        ->and(json_decode($install->work_capabilities, true, flags: JSON_THROW_ON_ERROR))->toBe([])
        ->and(json_decode($install->work_role_providers, true, flags: JSON_THROW_ON_ERROR))->toBe([])
        ->and($install->work_concurrency_ceiling)->toBe(1)
        ->and($install->script)->toContain('runtime.json', 'openusage-collector.php', 'capstan-machine/1')
        ->and($install->script)->not->toContain('CAPSTAN_WORK_')
        ->and($install->script)->not->toContain('getenv(');

    $payload = [
        'presence' => ['ready_inboxes' => []],
        'work_profile' => [
            'enabled' => false,
            'roles' => ['human'],
            'concurrency_ceiling' => 25,
        ],
    ];
    $this->withHeaders(machineUsageHeaders($owner, $issued))->postJson('/api/v1/poll', $payload)->assertOk();
    $spoke = Spoke::query()->sole();
    $setting = MachineTeamWorkSetting::query()->sole();

    expect($setting->spoke_id)->toBe($spoke->id)
        ->and($setting->team_id)->toBe(Team::default()->id)
        ->and($setting->enabled)->toBeTrue()
        ->and($setting->roles)->toBe($roles)
        ->and($setting->roles)->not->toContain('human')
        ->and($setting->capabilities)->toBe([])
        ->and($setting->role_providers)->toBe([])
        ->and($setting->concurrency_ceiling)->toBe(1)
        ->and($spoke->workSettingChanges()->count())->toBe(1);

    $applied = DB::table('postmaster_installs')->where('id', $install->id)->firstOrFail();
    expect($applied->applied_at)->not->toBeNull()
        ->and($applied->script)->toBe('')
        ->and($applied->team_id)->toBeNull()
        ->and($applied->work_roles)->toBeNull();

    $this->withHeaders(machineUsageHeaders($owner, $issued))->postJson('/api/v1/poll', $payload)->assertOk();
    expect(MachineTeamWorkSetting::query()->count())->toBe(1)
        ->and($spoke->workSettingChanges()->count())->toBe(1);
});

test('a pending profile is bound to its minted credential and rechecks owner authority', function (): void {
    $owner = capstanUser();
    $bound = capstanBoundBearer($owner, CapstanCredentialDeclaration::MACHINE);
    $foreign = capstanBoundBearer($owner, CapstanCredentialDeclaration::MACHINE);
    $install = bindPendingMachineInstall($this, $owner, $bound);

    $this->withHeaders(machineUsageHeaders($owner, $foreign))
        ->postJson('/api/v1/poll', ['presence' => ['ready_inboxes' => []]])
        ->assertOk();
    expect(MachineTeamWorkSetting::query()->count())->toBe(0)
        ->and(DB::table('postmaster_installs')->where('id', $install->id)->value('applied_at'))->toBeNull();

    $owner->forceFill(['status' => 'inactive'])->save();
    $this->withHeaders(machineUsageHeaders($owner, $bound))
        ->postJson('/api/v1/poll', ['presence' => ['ready_inboxes' => []]])
        ->assertUnauthorized();
    expect(MachineTeamWorkSetting::query()->count())->toBe(0)
        ->and(DB::table('postmaster_installs')->where('id', $install->id)->value('applied_at'))->toBeNull();
});

test('poll ingest is latest-only transactional and unavailable attempts preserve prior providers', function (): void {
    $now = CarbonImmutable::parse('2026-10-20T10:00:00Z');
    $this->travelTo($now);
    $owner = capstanUser();
    $issued = capstanBoundBearer($owner, CapstanCredentialDeclaration::MACHINE);
    $headers = machineUsageHeaders($owner, $issued);

    $this->withHeaders($headers)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
        'usage' => validMachineUsage(),
    ])->assertOk()->assertJsonPath('usage_status', 'accepted');
    $report = MachineUsageReport::query()->sole();
    expect($report->providers()->sole()->provider)->toBe('codex')
        ->and($report->providers()->sole()->windows()->sole()->remaining_percent)->toBe('72.500');

    $this->withHeaders($headers)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
        'usage' => validMachineUsage('claude', 10),
    ])->assertOk()->assertJsonPath('usage_status', 'not_due');
    expect($report->providers()->sole()->provider)->toBe('codex');

    $this->travel(301)->seconds();
    $unavailable = [
        'schema_version' => 1,
        'availability' => 'unavailable',
        'source' => 'none',
        'source_version' => null,
        'collected_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        'providers' => [],
        'failure' => 'timeout',
    ];
    $this->withHeaders($headers)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
        'usage' => $unavailable,
    ])->assertOk()->assertJsonPath('usage_status', 'accepted');

    expect($report->refresh()->availability)->toBe('unavailable')
        ->and($report->failure_code)->toBe('timeout')
        ->and($report->providers()->sole()->provider)->toBe('codex')
        ->and($report->last_success_received_at?->equalTo($now))->toBeTrue();
});

test('invalid usage is ignored atomically without blocking routing acknowledgements or presence', function (): void {
    $owner = capstanUser();
    $issued = capstanBoundBearer($owner, CapstanCredentialDeclaration::MACHINE);
    $canary = 'raw-account-secret-canary';
    $usage = validMachineUsage();
    $usage['providers'] = array_fill(0, 17, $usage['providers'][0]);
    $usage['forbidden_raw'] = $canary;

    $response = $this->withHeaders(machineUsageHeaders($owner, $issued))->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['builder']],
        'usage' => $usage,
    ])->assertOk()->assertJsonPath('usage_status', 'ignored_invalid');
    $spoke = Spoke::query()->sole();

    expect($spoke->last_polled_at)->not->toBeNull()
        ->and($spoke->inboxes()->sole()->local_part)->toBe('builder')
        ->and(MachineUsageReport::query()->count())->toBe(0)
        ->and(DB::table('machine_usage_providers')->count())->toBe(0)
        ->and(DB::table('machine_usage_windows')->count())->toBe(0)
        ->and($response->getContent())->not->toContain($canary);
});

test('usage pre-validation rejects oversized window cardinality and nesting before persistence', function (string $case): void {
    $owner = capstanUser();
    $issued = capstanBoundBearer($owner, CapstanCredentialDeclaration::MACHINE);
    $usage = validMachineUsage();

    if ($case === 'windows') {
        $usage['providers'][0]['windows'] = array_fill(0, 9, $usage['providers'][0]['windows'][0]);
    } elseif ($case === 'bytes') {
        $usage['forbidden'] = str_repeat('x', 65536);
    } else {
        $usage['forbidden'] = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => ['h' => ['i' => true]]]]]]]]];
    }

    $this->withHeaders(machineUsageHeaders($owner, $issued))->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
        'usage' => $usage,
    ])->assertOk()->assertJsonPath('usage_status', 'ignored_invalid');
    expect(MachineUsageReport::query()->count())->toBe(0);
})->with(['windows', 'bytes', 'depth']);

test('provider freshness is derived from server receipt and bounded source evidence', function (): void {
    $now = CarbonImmutable::parse('2026-10-20T10:00:00Z');
    $owner = capstanUser();
    $issued = capstanBoundBearer($owner, CapstanCredentialDeclaration::MACHINE);
    $spoke = Spoke::query()->create([
        'actor_id' => (string) $owner->getKey(),
        'credential_id' => $issued['credential']->id,
    ]);
    $report = MachineUsageReport::query()->create([
        'spoke_id' => $spoke->id,
        'source' => 'openusage-app',
        'availability' => 'available',
        'collected_at' => $now,
        'last_attempt_at' => $now,
        'last_success_received_at' => $now,
    ]);
    $provider = MachineUsageProvider::query()->create([
        'machine_usage_report_id' => $report->id,
        'provider' => 'codex',
        'fetched_at' => $now->subMinutes(15),
        'source_stale' => false,
        'expires_at' => $now->addSecond(),
        'status' => 'ok',
    ]);
    $freshness = resolve(MachineUsageFreshness::class);

    expect($freshness->providerIsFresh($report, $provider, $now))->toBeTrue()
        ->and($freshness->providerIsFresh($report, $provider, $now->addMinutes(10)->addSecond()))->toBeFalse();
    $provider->forceFill(['fetched_at' => $now->addMinutes(5)->addSecond(), 'expires_at' => null])->save();
    expect($freshness->providerIsFresh($report, $provider->refresh(), $now))->toBeFalse();
});

test('legacy rows four and five upgrade in place to machine credentials without disturbing tombstones', function (): void {
    $owner = capstanUser();
    $team = Team::default();
    $retiredCredentialId = (string) fake()->uuid();
    $retired = Spoke::query()->forceCreate([
        'id' => 2,
        'actor_id' => (string) $owner->getKey(),
        'credential_id' => $retiredCredentialId,
        'name' => 'Retired drain tombstone',
        'retired_at' => now()->subDay(),
        'retired_by' => (string) $owner->getKey(),
    ]);
    $fixtures = [];
    foreach ([4, 5] as $id) {
        $old = capstanBoundBearer($owner, CapstanCredentialDeclaration::POSTMASTER_POLL);
        $new = capstanBoundBearer($owner, CapstanCredentialDeclaration::MACHINE);
        $spoke = Spoke::query()->forceCreate([
            'id' => $id,
            'actor_id' => (string) $owner->getKey(),
            'credential_id' => $old['credential']->id,
            'name' => 'Live machine '.$id,
            'device_name' => 'Decorative host '.$id,
        ]);
        $inbox = Inbox::query()->create([
            'actor_id' => (string) $owner->getKey(),
            'local_part' => 'machine-'.$id,
        ]);
        $spoke->inboxes()->attach($inbox);
        bindPendingMachineInstall($this, $owner, $new);
        $fixtures[] = compact('id', 'old', 'new', 'spoke', 'inbox');
    }
    $activeBefore = Spoke::query()->whereNull('retired_at')->count();

    foreach ($fixtures as $fixture) {
        $headers = machineUsageHeaders($owner, $fixture['new']);
        $headers[ReplacePostmasterSpokeController::PREVIOUS_CREDENTIAL_HEADER] = $fixture['old']['token'];
        $this->withHeaders($headers)->postJson('/api/v1/postmaster/replace')
            ->assertOk()
            ->assertJsonPath('spoke_id', $fixture['id'])
            ->assertJsonPath('replaced', true);
        $this->withHeaders(machineUsageHeaders($owner, $fixture['new']))->postJson('/api/v1/poll', [
            'presence' => ['ready_inboxes' => ['machine-'.$fixture['id']]],
        ])->assertOk();
        $this->withHeaders(machineUsageHeaders($owner, $fixture['new']))->postJson(route('api.work.claim', $team), [
            'capacity' => 0, 'roles' => ['builder'], 'capabilities' => [],
        ])->assertOk();

        $spoke = $fixture['spoke']->refresh();
        expect($spoke->id)->toBe($fixture['id'])
            ->and($spoke->actor_id)->toBe((string) $owner->getKey())
            ->and($spoke->credential_id)->toBe($fixture['new']['credential']->id)
            ->and($spoke->name)->toBe('Live machine '.$fixture['id'])
            ->and($spoke->device_name)->toBe('Decorative host '.$fixture['id'])
            ->and($spoke->inboxes()->sole()->id)->toBe($fixture['inbox']->id)
            ->and($fixture['old']['credential']->refresh()->revoked_at)->not->toBeNull()
            ->and($fixture['new']['credential']->refresh()->revoked_at)->toBeNull()
            ->and($spoke->workSettings()->sole()->enabled)->toBeTrue();
    }

    expect(Spoke::query()->whereNull('retired_at')->count())->toBe($activeBefore)
        ->and($retired->refresh()->retired_at)->not->toBeNull()
        ->and($retired->credential_id)->toBe($retiredCredentialId);
});
