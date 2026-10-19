<?php

use App\Auth\CapstanCredentialDeclaration;
use App\Models\Spoke;
use App\Models\Team;
use ArtisanBuild\BuiltForCloud\ClientIdentity;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\Hmac\SigningRootLifecycle;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('m', 32)),
        'app.url' => 'https://app.capstan.test',
        'capstan.artifacts.render_origin' => 'https://artifacts.capstan.test',
        'capstan.features.artifacts' => true,
        'capstan.features.postmaster' => true,
        'capstan.features.work' => true,
        'capstan.postmaster.server_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'capstan.postmaster.signing_key' => 'machine-auth-test-signing-key',
    ]);
    Feature::flushCache();
    resolve(SigningRootLifecycle::class)->provision();
});

/** @return array<string, mixed> */
function machineEndpointPayload(string $endpoint): array
{
    return match ($endpoint) {
        'artifact' => [],
        'poll' => ['presence' => ['ready_inboxes' => []]],
        'replace' => [],
        'work' => ['capacity' => 0, 'roles' => ['builder'], 'capabilities' => []],
    };
}

function machineEndpointUrl(string $endpoint, Team $team): string
{
    return match ($endpoint) {
        'artifact' => '/api/v1/artifacts',
        'poll' => '/api/v1/poll',
        'replace' => '/api/v1/postmaster/replace',
        'work' => route('api.work.claim', $team),
    };
}

test('the machine and legacy purpose endpoint matrix is exact', function (
    string $purpose,
    string $endpoint,
    int $status,
    string $code,
): void {
    $user = capstanUser();
    $team = Team::default();
    $issued = capstanBoundBearer($user, $purpose);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$issued['token'],
        CapstanCredentialDeclaration::ACTOR_HEADER => (string) $user->getKey(),
        ClientIdentity::HEADER => 'capstan-machine-test/1',
    ])->postJson(machineEndpointUrl($endpoint, $team), machineEndpointPayload($endpoint));

    $response->assertStatus($status);

    if ($code !== '') {
        $response->assertJsonPath('error.code', $code);
    } else {
        $response->assertJsonMissingPath('error');
    }
})->with([
    'machine polls' => [CapstanCredentialDeclaration::MACHINE, 'poll', 200, ''],
    'machine replaces' => [CapstanCredentialDeclaration::MACHINE, 'replace', 200, ''],
    'machine claims Work' => [CapstanCredentialDeclaration::MACHINE, 'work', 200, ''],
    'machine cannot ingest artifacts' => [CapstanCredentialDeclaration::MACHINE, 'artifact', 401, 'unauthenticated'],
    'legacy Postmaster polls' => [CapstanCredentialDeclaration::POSTMASTER_POLL, 'poll', 200, ''],
    'legacy Postmaster replaces' => [CapstanCredentialDeclaration::POSTMASTER_POLL, 'replace', 200, ''],
    'legacy Postmaster cannot claim Work' => [CapstanCredentialDeclaration::POSTMASTER_POLL, 'work', 401, 'unauthenticated'],
    'legacy drain claims Work' => [CapstanCredentialDeclaration::WORK_DRAIN, 'work', 200, ''],
    'legacy drain cannot poll' => [CapstanCredentialDeclaration::WORK_DRAIN, 'poll', 401, 'unauthenticated'],
    'legacy drain cannot replace' => [CapstanCredentialDeclaration::WORK_DRAIN, 'replace', 401, 'unauthenticated'],
    'artifact credential reaches artifact validation' => [CapstanCredentialDeclaration::ARTIFACT_INGEST, 'artifact', 422, 'validation_failed'],
    'artifact credential cannot poll' => [CapstanCredentialDeclaration::ARTIFACT_INGEST, 'poll', 401, 'unauthenticated'],
    'artifact credential cannot replace' => [CapstanCredentialDeclaration::ARTIFACT_INGEST, 'replace', 401, 'unauthenticated'],
    'artifact credential cannot claim Work' => [CapstanCredentialDeclaration::ARTIFACT_INGEST, 'work', 401, 'unauthenticated'],
]);

test('the machine authorization profile has the locked purpose audience and browser owner', function (): void {
    $owner = capstanUser();
    $other = capstanUser();
    $request = Request::create('/bfc/credentials/device', 'POST', server: [
        'HTTP_X_CAPSTAN_ACTOR_ID' => (string) $other->getKey(),
    ]);
    $request->setUserResolver(static fn (): User => $owner);

    $profile = collect(resolve(CapstanCredentialDeclaration::class)->credentialAuthorizationProfiles($request))
        ->sole(fn ($profile): bool => $profile->appPurpose === CapstanCredentialDeclaration::MACHINE);

    expect($profile->appPurpose)->toBe('capstan.machine')
        ->and($profile->scope->appPurpose)->toBe('capstan.machine')
        ->and($profile->scope->audience)->toBe('/api/v1/machine')
        ->and($profile->scope->subject->ref)->toBe('capstan-user:'.$owner->getKey())
        ->and($profile->scope->subject->ref)->not->toBe('capstan-user:'.$other->getKey());
});

test('one machine credential resolves the same spoke for poll and Work', function (): void {
    $user = capstanUser();
    $issued = capstanBoundBearer($user, CapstanCredentialDeclaration::MACHINE);
    $headers = [
        'Authorization' => 'Bearer '.$issued['token'],
        CapstanCredentialDeclaration::ACTOR_HEADER => (string) $user->getKey(),
    ];

    $this->withHeaders($headers)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk();
    $spokeId = Spoke::query()->sole()->id;

    $this->withHeaders($headers)->postJson(route('api.work.claim', Team::default()), [
        'capacity' => 0,
        'roles' => ['builder'],
        'capabilities' => [],
    ])->assertOk();

    expect(Spoke::query()->count())->toBe(1)
        ->and(Spoke::query()->sole()->id)->toBe($spokeId)
        ->and(Spoke::query()->sole()->credential_id)->toBe($issued['credential']->id);
});

test('machine authentication retains owner and credential lifecycle gates', function (string $case): void {
    $owner = capstanUser();
    $other = capstanUser();
    $issued = capstanBoundBearer($owner, CapstanCredentialDeclaration::MACHINE);
    $actorId = (string) $owner->getKey();

    if ($case === 'inactive') {
        $owner->forceFill(['status' => 'inactive'])->save();
    } elseif ($case === 'revoked') {
        $issued['credential']->forceFill(['revoked_at' => now()])->save();
    } elseif ($case === 'expired') {
        $issued['credential']->forceFill(['expires_at' => now()->subSecond()])->save();
    } elseif ($case === 'offboarded') {
        OffboardedSubject::query()->create([
            'subject_type' => SubjectType::UserPrincipal,
            'subject_ref' => 'capstan-user:'.$owner->getKey(),
            'user_id' => (string) $owner->getKey(),
            'offboarded_at' => now(),
        ]);
    } elseif (in_array($case, ['retired', 'orphaned'], true)) {
        Spoke::query()->create([
            'actor_id' => $actorId,
            'credential_id' => $issued['credential']->id,
            $case.'_at' => now(),
        ]);
    } elseif ($case === 'swapped owner') {
        $actorId = (string) $other->getKey();
    }

    $this->withHeaders([
        'Authorization' => 'Bearer '.$issued['token'],
        CapstanCredentialDeclaration::ACTOR_HEADER => $actorId,
    ])->postJson('/api/v1/poll', ['presence' => ['ready_inboxes' => []]])
        ->assertStatus(401)
        ->assertExactJson([
            'error' => [
                'code' => 'unauthenticated',
                'message' => 'Unauthenticated.',
            ],
        ]);

    expect(Spoke::query()->whereNull('retired_at')->whereNull('orphaned_at')->count())->toBe(0);
})->with(['swapped owner', 'inactive', 'revoked', 'expired', 'offboarded', 'retired', 'orphaned']);

test('authenticated api limits are independent per credential for one actor', function (): void {
    Date::setTestNow('2026-10-19 12:00:00');
    $owner = capstanUser();
    $first = capstanBoundBearer($owner, CapstanCredentialDeclaration::MACHINE);
    $second = capstanBoundBearer($owner, CapstanCredentialDeclaration::MACHINE);
    $headers = fn (array $issued): array => [
        'Authorization' => 'Bearer '.$issued['token'],
        CapstanCredentialDeclaration::ACTOR_HEADER => (string) $owner->getKey(),
    ];

    foreach (range(1, 60) as $attempt) {
        $this->withHeaders($headers($first))->postJson('/api/v1/poll', [
            'presence' => ['ready_inboxes' => []],
        ])->assertOk()->assertHeader('X-RateLimit-Remaining', (string) (60 - $attempt));
    }

    $this->withHeaders($headers($first))->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertTooManyRequests()->assertHeader('X-RateLimit-Remaining', '0');

    $this->withHeaders($headers($second))->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk()->assertHeader('X-RateLimit-Remaining', '59');

    expect(Spoke::query()->count())->toBe(2)
        ->and(Credential::query()->whereNotNull('last_used_at')->count())->toBeGreaterThanOrEqual(2);
});

test('an orphaned machine credential cannot poll or claim Work', function (): void {
    $owner = capstanUser();
    $issued = capstanBoundBearer($owner, CapstanCredentialDeclaration::MACHINE);
    Spoke::query()->create([
        'actor_id' => (string) $owner->getKey(),
        'credential_id' => $issued['credential']->id,
        'orphaned_at' => now(),
    ]);
    $headers = [
        'Authorization' => 'Bearer '.$issued['token'],
        CapstanCredentialDeclaration::ACTOR_HEADER => (string) $owner->getKey(),
    ];

    $this->withHeaders($headers)->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => []],
    ])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

    $this->withHeaders($headers)->postJson(route('api.work.claim', Team::default()), [
        'capacity' => 1,
        'roles' => ['builder'],
        'capabilities' => [],
    ])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

    expect(Spoke::query()->count())->toBe(1);
});
