<?php

use App\Auth\CapstanCredentialDeclaration;
use ArtisanBuild\BuiltForCloud\AppPurposeRegistry;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialAuthorizationProfile;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/** @param array<string, mixed> $attributes */
function capstanUser(array $attributes = []): User
{
    $suffix = bin2hex(random_bytes(6));
    $user = new User;
    $user->forceFill(array_replace([
        'name' => 'Capstan test user',
        'email' => "capstan-{$suffix}@example.test",
        'password' => bcrypt('test-created-password'),
        'role' => UserRole::Member->value,
        'status' => 'active',
    ], $attributes));
    $user->save();

    return $user;
}

/**
 * @return array{token: string, credential: Credential}
 */
function capstanBoundBearer(
    User $user,
    string $appPurpose,
): array {
    $request = Request::create(
        $appPurpose === CapstanCredentialDeclaration::ARTIFACT_INGEST
            ? '/api/v1/artifacts'
            : '/api/v1/poll',
        'POST',
        server: ['HTTP_X_CAPSTAN_ACTOR_ID' => (string) $user->getKey()],
    );
    $profile = collect(app(CapstanCredentialDeclaration::class)->credentialAuthorizationProfiles($request))
        ->first(fn (CredentialAuthorizationProfile $profile): bool => $profile->appPurpose === $appPurpose);
    expect($profile)->toBeInstanceOf(CredentialAuthorizationProfile::class);

    $token = 'capstan-test-'.bin2hex(random_bytes(24));
    $credential = new Credential;
    $credential->forceFill([
        'kind' => CredentialKind::Bearer,
        'purpose' => app(AppPurposeRegistry::class)->purpose($appPurpose),
        'subject_type' => $profile->scope->subject->type,
        'subject_ref' => $profile->scope->subject->ref,
        'name' => 'Capstan test credential',
        'abilities' => [],
        'user_id' => (string) $user->getKey(),
        'secret_hash' => hash('sha256', $token),
        'status' => CredentialStatus::Active,
        'expires_at' => $profile->expiresAt,
    ]);

    DB::transaction(fn () => $credential->saveWithOriginatorBinding($profile->scope));

    return ['token' => $token, 'credential' => $credential];
}

/** @return array<string, string> */
function capstanBearerHeaders(
    User $user,
    string $appPurpose,
    ?string $actorId = null,
): array {
    $issued = capstanBoundBearer($user, $appPurpose);

    return [
        'Authorization' => 'Bearer '.$issued['token'],
        CapstanCredentialDeclaration::ACTOR_HEADER => $actorId ?? (string) $user->getKey(),
    ];
}
