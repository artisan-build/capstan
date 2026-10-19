<?php

use App\Auth\CapstanCredentialDeclaration;
use App\Enums\MachineState;
use App\Enums\SpokeLiveness;
use App\Http\Controllers\Api\ReplacePostmasterSpokeController;
use App\Models\Inbox;
use App\Models\MachineTeamWorkSetting;
use App\Models\Spoke;
use App\Models\Team;
use App\Models\WorkItem;
use App\Models\WorkTask;
use App\Postmaster\MachineLifecycle;
use App\Postmaster\MarkInactiveMachines;
use ArtisanBuild\BuiltForCloud\Actions\OffboardSubject as OffboardSubjectAction;
use ArtisanBuild\BuiltForCloud\Actions\RevokeCredential;
use ArtisanBuild\BuiltForCloud\AuditReason;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialAuditEvent;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\Hmac\SigningRootLifecycle;
use ArtisanBuild\BuiltForCloud\LifecycleEventType;
use ArtisanBuild\BuiltForCloud\OffboardOptions;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('l', 32)),
        'capstan.features.postmaster' => true,
        'capstan.features.work' => true,
        'capstan.postmaster.server_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'capstan.postmaster.signing_key' => 'machine-lifecycle-test-signing-key',
    ]);
    Feature::flushCache();
    resolve(SigningRootLifecycle::class)->provision();
});

/** @return array{token: string, credential: Credential} */
function machineLifecycleCredential(User $user): array
{
    return capstanBoundBearer($user, CapstanCredentialDeclaration::MACHINE);
}

/** @param array{token: string, credential: Credential} $new */
function replaceOrphanedMachine(object $test, User $newOwner, array $new, ?string $oldToken)
{
    $headers = [
        'Authorization' => 'Bearer '.$new['token'],
        CapstanCredentialDeclaration::ACTOR_HEADER => (string) $newOwner->getKey(),
    ];

    if ($oldToken !== null) {
        $headers[ReplacePostmasterSpokeController::PREVIOUS_CREDENTIAL_HEADER] = $oldToken;
    }

    return $test->withHeaders($headers)->postJson(route('api.postmaster.replace'));
}

function configuredMachine(Spoke $spoke, User $actor): MachineTeamWorkSetting
{
    return MachineTeamWorkSetting::query()->create([
        'spoke_id' => $spoke->id,
        'team_id' => Team::default()->id,
        'enabled' => true,
        'roles' => ['builder'],
        'capabilities' => [],
        'role_providers' => [],
        'concurrency_ceiling' => 1,
        'updated_by_actor' => (string) $actor->getKey(),
    ]);
}

function offboardMachineOwner(User $owner): void
{
    resolve(OffboardSubjectAction::class)(new OffboardOptions(
        SubjectType::UserPrincipal,
        'capstan-user:'.$owner->getKey(),
    ));
}

test('machine lifecycle precedence is retired orphaned unhealthy inactive active then pending', function (): void {
    $now = CarbonImmutable::parse('2026-10-19 12:00:00');
    $owner = capstanUser();
    $spoke = Spoke::query()->create([
        'actor_id' => (string) $owner->getKey(),
        'credential_id' => (string) Str::uuid7(),
        'last_polled_at' => $now,
        'probe_status' => SpokeLiveness::Unknown,
    ]);
    $lifecycle = resolve(MachineLifecycle::class);

    expect($lifecycle->state($spoke, $now))->toBe(MachineState::Pending);
    $spoke->forceFill(['probe_status' => SpokeLiveness::Green])->save();
    expect($lifecycle->state($spoke->fresh(), $now))->toBe(MachineState::Active);
    expect($lifecycle->state($spoke->fresh(), $now->addSeconds(301)))->toBe(MachineState::Inactive);
    $spoke->forceFill(['probe_status' => SpokeLiveness::Red])->save();
    expect($lifecycle->state($spoke->fresh(), $now->addSeconds(301)))->toBe(MachineState::Unhealthy);
    $spoke->forceFill(['orphaned_at' => $now])->save();
    expect($lifecycle->state($spoke->fresh(), $now->addSeconds(301)))->toBe(MachineState::Orphaned);
    $spoke->forceFill(['retired_at' => $now])->save();
    expect($lifecycle->state($spoke->fresh(), $now->addSeconds(301)))->toBe(MachineState::Retired);
});

test('quiet machines become inactive and a successful poll clears inactivity without retirement', function (): void {
    $now = CarbonImmutable::parse('2026-10-19 12:00:00');
    $owner = capstanUser();
    $issued = machineLifecycleCredential($owner);
    $spoke = Spoke::query()->create([
        'actor_id' => (string) $owner->getKey(),
        'credential_id' => $issued['credential']->id,
        'last_polled_at' => $now->subSeconds(301),
        'probe_status' => SpokeLiveness::Green,
    ]);

    expect(resolve(MarkInactiveMachines::class)($now))->toBe(1)
        ->and($spoke->refresh()->inactive_at?->equalTo($now))->toBeTrue()
        ->and($spoke->retired_at)->toBeNull();

    $this->travelTo($now->addMinute());
    $this->withHeaders([
        'Authorization' => 'Bearer '.$issued['token'],
        CapstanCredentialDeclaration::ACTOR_HEADER => (string) $owner->getKey(),
    ])->postJson('/api/v1/poll', ['presence' => ['ready_inboxes' => []]])->assertOk();

    expect($spoke->refresh()->inactive_at)->toBeNull()
        ->and($spoke->retired_at)->toBeNull();
});

test('dead credential reconciliation orphans offboarded owners and retires otherwise dead credentials', function (): void {
    $orphanOwner = capstanUser();
    $retiredOwner = capstanUser();
    $orphanCredential = machineLifecycleCredential($orphanOwner);
    $retiredCredential = machineLifecycleCredential($retiredOwner);
    $orphan = Spoke::query()->create([
        'actor_id' => (string) $orphanOwner->getKey(),
        'credential_id' => $orphanCredential['credential']->id,
    ]);
    $retired = Spoke::query()->create([
        'actor_id' => (string) $retiredOwner->getKey(),
        'credential_id' => $retiredCredential['credential']->id,
    ]);
    offboardMachineOwner($orphanOwner);
    $retiredCredential['credential']->forceFill(['revoked_at' => now()])->save();

    $this->artisan('postmaster:retire-dead-spokes', ['--local' => true])->assertSuccessful();

    expect($orphan->refresh()->orphaned_at)->not->toBeNull()
        ->and($orphan->retired_at)->toBeNull()
        ->and($retired->refresh()->retired_at)->not->toBeNull()
        ->and($retired->orphaned_at)->toBeNull();

    $orphanOwner->forceFill(['status' => 'active'])->save();
    DB::table('offboarded_subjects')->delete();
    $this->artisan('postmaster:retire-dead-spokes', ['--local' => true])->assertSuccessful();
    expect($orphan->refresh()->orphaned_at)->not->toBeNull()
        ->and($orphan->retired_at)->toBeNull();
});

test('revoked expired and missing credentials retire while quiet live credentials remain recoverable', function (): void {
    $owner = capstanUser();
    $revoked = machineLifecycleCredential($owner);
    $expired = machineLifecycleCredential($owner);
    $live = machineLifecycleCredential($owner);
    $spokes = collect([
        'revoked' => Spoke::query()->create([
            'actor_id' => (string) $owner->getKey(),
            'credential_id' => $revoked['credential']->id,
        ]),
        'expired' => Spoke::query()->create([
            'actor_id' => (string) $owner->getKey(),
            'credential_id' => $expired['credential']->id,
        ]),
        'missing' => Spoke::query()->create([
            'actor_id' => (string) $owner->getKey(),
            'credential_id' => (string) Str::uuid7(),
        ]),
        'quiet-live' => Spoke::query()->create([
            'actor_id' => (string) $owner->getKey(),
            'credential_id' => $live['credential']->id,
            'last_polled_at' => now()->subDay(),
        ]),
    ]);
    $revoked['credential']->forceFill(['revoked_at' => now()])->save();
    $expired['credential']->forceFill(['expires_at' => now()->subSecond()])->save();

    $this->artisan('postmaster:retire-dead-spokes', ['--local' => true])
        ->expectsOutput('Retired 3 dead Postmaster spoke(s).')
        ->assertSuccessful();

    expect($spokes['revoked']->refresh()->retired_at)->not->toBeNull()
        ->and($spokes['expired']->refresh()->retired_at)->not->toBeNull()
        ->and($spokes['missing']->refresh()->retired_at)->not->toBeNull()
        ->and($spokes['quiet-live']->refresh()->retired_at)->toBeNull()
        ->and($spokes['quiet-live']->orphaned_at)->toBeNull();
});

test('exact on-machine proof transfers an orphaned machine and preserves its history', function (): void {
    $oldOwner = capstanUser();
    $newOwner = capstanUser();
    $old = machineLifecycleCredential($oldOwner);
    $new = machineLifecycleCredential($newOwner);
    $spoke = Spoke::query()->create([
        'actor_id' => (string) $oldOwner->getKey(),
        'credential_id' => $old['credential']->id,
        'name' => 'Kept machine history',
    ]);
    configuredMachine($spoke, $oldOwner);
    $inbox = Inbox::query()->create([
        'actor_id' => (string) $oldOwner->getKey(),
        'local_part' => 'kept-machine-history',
    ]);
    $spoke->inboxes()->attach($inbox);
    offboardMachineOwner($oldOwner);
    $this->artisan('postmaster:retire-dead-spokes', ['--local' => true])->assertSuccessful();

    expect(CredentialAuditEvent::query()
        ->where('credential_id', $old['credential']->id)
        ->where('event', LifecycleEventType::Revoked->value)
        ->where('reason_code', AuditReason::Offboarding->value)
        ->count())->toBe(1)
        ->and($spoke->refresh()->orphaned_at)->not->toBeNull();

    replaceOrphanedMachine($this, $newOwner, $new, $old['token'])
        ->assertOk()
        ->assertJsonPath('spoke_id', $spoke->id)
        ->assertJsonPath('replaced', true);

    $spoke->refresh();
    expect(Spoke::query()->count())->toBe(1)
        ->and($spoke->actor_id)->toBe((string) $newOwner->getKey())
        ->and($spoke->credential_id)->toBe($new['credential']->id)
        ->and($spoke->orphaned_at)->toBeNull()
        ->and($spoke->name)->toBe('Kept machine history')
        ->and($spoke->inboxes()->count())->toBe(0)
        ->and($inbox->fresh()->id)->toBe($inbox->id)
        ->and($old['credential']->refresh()->revoked_at)->not->toBeNull();
});

test('invalid missing ineligible retired and replayed orphan proofs create a fresh row without partial transfer', function (string $case): void {
    $oldOwner = capstanUser();
    $newOwner = capstanUser();
    $old = machineLifecycleCredential($oldOwner);
    $newPurpose = $case === 'legacy approver'
        ? CapstanCredentialDeclaration::POSTMASTER_POLL
        : CapstanCredentialDeclaration::MACHINE;
    $new = capstanBoundBearer($newOwner, $newPurpose);
    $spoke = Spoke::query()->create([
        'actor_id' => (string) $oldOwner->getKey(),
        'credential_id' => $old['credential']->id,
        'orphaned_at' => now(),
        'retired_at' => $case === 'retired' ? now() : null,
    ]);

    if ($case !== 'no configured team') {
        configuredMachine($spoke, $oldOwner);
    }

    $proof = match ($case) {
        'missing' => null,
        'invalid' => 'invalid-prior-machine-secret',
        default => $old['token'],
    };

    replaceOrphanedMachine($this, $newOwner, $new, $proof)
        ->assertOk()
        ->assertJsonPath('replaced', false);

    $spoke->refresh();
    expect($spoke->actor_id)->toBe((string) $oldOwner->getKey())
        ->and($spoke->credential_id)->toBe($old['credential']->id)
        ->and($spoke->orphaned_at)->not->toBeNull()
        ->and(Spoke::query()->where('actor_id', (string) $newOwner->getKey())->count())->toBe(1);

})->with(['missing', 'invalid', 'no configured team', 'legacy approver', 'retired']);

test('an orphan proof cannot be replayed after a successful transfer', function (): void {
    $oldOwner = capstanUser();
    $newOwner = capstanUser();
    $thirdOwner = capstanUser();
    $old = machineLifecycleCredential($oldOwner);
    $new = machineLifecycleCredential($newOwner);
    $third = machineLifecycleCredential($thirdOwner);
    $spoke = Spoke::query()->create([
        'actor_id' => (string) $oldOwner->getKey(),
        'credential_id' => $old['credential']->id,
    ]);
    configuredMachine($spoke, $oldOwner);
    offboardMachineOwner($oldOwner);
    $this->artisan('postmaster:retire-dead-spokes', ['--local' => true])->assertSuccessful();

    replaceOrphanedMachine($this, $newOwner, $new, $old['token'])
        ->assertOk()
        ->assertJsonPath('replaced', true)
        ->assertJsonPath('spoke_id', $spoke->id);
    replaceOrphanedMachine($this, $thirdOwner, $third, $old['token'])
        ->assertOk()
        ->assertJsonPath('replaced', false);

    expect($spoke->refresh()->actor_id)->toBe((string) $newOwner->getKey())
        ->and($spoke->credential_id)->toBe($new['credential']->id)
        ->and(Spoke::query()->where('actor_id', (string) $thirdOwner->getKey())->count())->toBe(1);
});

test('an orphan with live Work authority cannot transfer or partially mutate', function (): void {
    $oldOwner = capstanUser();
    $newOwner = capstanUser();
    $old = machineLifecycleCredential($oldOwner);
    $new = machineLifecycleCredential($newOwner);
    $spoke = Spoke::query()->create([
        'actor_id' => (string) $oldOwner->getKey(),
        'credential_id' => $old['credential']->id,
    ]);
    configuredMachine($spoke, $oldOwner);
    offboardMachineOwner($oldOwner);
    $this->artisan('postmaster:retire-dead-spokes', ['--local' => true])->assertSuccessful();
    $task = WorkTask::query()->create([
        'team_id' => Team::default()->id,
        'title' => 'Orphan transfer conflict',
        'required_capabilities' => [],
        'timebox_minutes' => 30,
        'creator_actor' => (string) $oldOwner->getKey(),
    ]);
    WorkItem::query()->create([
        'work_task_id' => $task->id,
        'role' => 'builder',
        'state' => 'claimed',
        'claimant_spoke_id' => $spoke->id,
        'claimant_actor' => (string) $oldOwner->getKey(),
        'lease_until' => now()->addMinute(),
        'claimed_at' => now(),
        'fence' => 1,
    ]);

    replaceOrphanedMachine($this, $newOwner, $new, $old['token'])
        ->assertOk()
        ->assertJsonPath('replaced', false);

    expect($spoke->refresh()->actor_id)->toBe((string) $oldOwner->getKey())
        ->and($spoke->credential_id)->toBe($old['credential']->id)
        ->and($spoke->orphaned_at)->not->toBeNull()
        ->and($old['credential']->refresh()->revoked_at)->not->toBeNull()
        ->and(Spoke::query()->where('actor_id', (string) $newOwner->getKey())->count())->toBe(1);
});

test('revocation expiry and non-active state never gain orphan transfer authority', function (string $case): void {
    $oldOwner = capstanUser();
    $newOwner = capstanUser();
    $old = machineLifecycleCredential($oldOwner);
    $new = machineLifecycleCredential($newOwner);
    $spoke = Spoke::query()->create([
        'actor_id' => (string) $oldOwner->getKey(),
        'credential_id' => $old['credential']->id,
        'orphaned_at' => now(),
    ]);
    configuredMachine($spoke, $oldOwner);

    if ($case === 'explicit revoke before offboarding') {
        (resolve(RevokeCredential::class))($old['credential']->id);
        offboardMachineOwner($oldOwner);
    } elseif ($case === 'explicit revoke after orphaning') {
        (resolve(RevokeCredential::class))($old['credential']->id);
    } elseif ($case === 'expired') {
        $old['credential']->forceFill(['expires_at' => now()->subSecond(), 'revoked_at' => now()])->save();
    } elseif ($case === 'non-active') {
        $old['credential']->forceFill(['status' => CredentialStatus::Pending, 'revoked_at' => now()])->save();
    }

    replaceOrphanedMachine($this, $newOwner, $new, $old['token'])
        ->assertOk()
        ->assertJsonPath('replaced', false);

    $unchanged = $spoke->refresh();
    expect($unchanged->id)->toBe($spoke->id)
        ->and($unchanged->actor_id)->toBe((string) $oldOwner->getKey())
        ->and($unchanged->credential_id)->toBe($old['credential']->id)
        ->and($unchanged->orphaned_at)->not->toBeNull()
        ->and($unchanged->retired_at)->toBeNull()
        ->and(Spoke::query()->where('actor_id', (string) $newOwner->getKey())->count())->toBe(1);
})->with(['explicit revoke before offboarding', 'explicit revoke after orphaning', 'expired', 'non-active']);
