<?php

use App\Auth\CapstanCredentialDeclaration;
use App\Http\Controllers\Api\ReplacePostmasterSpokeController;
use App\Models\Spoke;
use App\Postmaster\ReplaceSpokeCredential;
use App\Postmaster\RetireSpoke;
use ArtisanBuild\BuiltForCloud\BoundBearerCredential;
use ArtisanBuild\BuiltForCloud\BoundBearerCredentialAuthenticator;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('r', 32)),
        'capstan.features.postmaster' => true,
    ]);
    Feature::flushCache();
});

/** @param array{token: string, credential: Credential} $issued */
function replaceSpokeRequest(object $test, User $user, array $issued, ?string $oldToken): TestResponse
{
    $headers = [
        'Authorization' => 'Bearer '.$issued['token'],
        CapstanCredentialDeclaration::ACTOR_HEADER => (string) $user->getKey(),
    ];

    if ($oldToken !== null) {
        $headers[ReplacePostmasterSpokeController::PREVIOUS_CREDENTIAL_HEADER] = $oldToken;
    }

    return $test->withHeaders($headers)->postJson(route('api.postmaster.replace'));
}

function authenticatedReplacementCredential(User $user, string $token): BoundBearerCredential
{
    $request = Request::create('/api/v1/postmaster/replace', 'POST', server: [
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        'HTTP_X_CAPSTAN_ACTOR_ID' => (string) $user->getKey(),
    ]);
    $credential = resolve(BoundBearerCredentialAuthenticator::class)
        ->authenticate($request, CapstanCredentialDeclaration::POSTMASTER_POLL);
    expect($credential)->toBeInstanceOf(BoundBearerCredential::class);

    return $credential;
}

test('reinstall rebinds the existing spoke revokes the old credential and keeps its history', function (): void {
    $user = capstanUser();
    $old = capstanBoundBearer($user, CapstanCredentialDeclaration::POSTMASTER_POLL);
    $new = capstanBoundBearer($user, CapstanCredentialDeclaration::POSTMASTER_POLL);

    $this->withToken($old['token'])->withHeader(CapstanCredentialDeclaration::ACTOR_HEADER, (string) $user->id)
        ->postJson('/api/v1/poll', ['presence' => ['ready_inboxes' => []]])->assertOk();
    $spoke = Spoke::query()->sole();
    $spoke->forceFill(['name' => 'Kept history'])->save();

    replaceSpokeRequest($this, $user, $new, $old['token'])
        ->assertOk()
        ->assertJsonPath('spoke_id', $spoke->id)
        ->assertJsonPath('replaced', true);

    expect(Spoke::query()->count())->toBe(1)
        ->and($spoke->refresh()->credential_id)->toBe($new['credential']->id)
        ->and($spoke->name)->toBe('Kept history')
        ->and($old['credential']->refresh()->revoked_at)->not->toBeNull();

    $this->withToken($old['token'])->withHeader(CapstanCredentialDeclaration::ACTOR_HEADER, (string) $user->id)
        ->postJson('/api/v1/poll', ['presence' => ['ready_inboxes' => []]])->assertUnauthorized();

    $before = $spoke->last_polled_at;
    $this->travel(1)->minutes();
    $this->withToken($new['token'])->withHeader(CapstanCredentialDeclaration::ACTOR_HEADER, (string) $user->id)
        ->postJson('/api/v1/poll', ['presence' => ['ready_inboxes' => []]])->assertOk();
    expect($spoke->refresh()->last_polled_at?->greaterThan($before))->toBeTrue()
        ->and(Spoke::query()->count())->toBe(1);
});

test('missing invalid revoked and foreign old credentials each produce a fresh spoke', function (string $case): void {
    $user = capstanUser();
    $new = capstanBoundBearer($user, CapstanCredentialDeclaration::POSTMASTER_POLL);
    $oldToken = null;

    if ($case === 'invalid') {
        $oldToken = 'not-a-valid-token';
    } elseif ($case === 'revoked') {
        $old = capstanBoundBearer($user, CapstanCredentialDeclaration::POSTMASTER_POLL);
        $old['credential']->forceFill(['revoked_at' => now()])->save();
        $oldToken = $old['token'];
    } elseif ($case === 'foreign') {
        $foreign = capstanBoundBearer(capstanUser(), CapstanCredentialDeclaration::POSTMASTER_POLL);
        $oldToken = $foreign['token'];
    }

    replaceSpokeRequest($this, $user, $new, $oldToken)
        ->assertOk()
        ->assertJsonPath('replaced', false);

    expect(Spoke::query()->where('actor_id', (string) $user->id)->sole()->credential_id)
        ->toBe($new['credential']->id);
})->with(['missing', 'invalid', 'revoked', 'foreign']);

test('a credential from a retired spoke cannot replace it and instead registers a fresh spoke', function (): void {
    $user = capstanUser();
    $old = capstanBoundBearer($user, CapstanCredentialDeclaration::POSTMASTER_POLL);
    $new = capstanBoundBearer($user, CapstanCredentialDeclaration::POSTMASTER_POLL);
    $spoke = Spoke::query()->create([
        'actor_id' => (string) $user->id,
        'credential_id' => $old['credential']->id,
    ]);
    resolve(RetireSpoke::class)->handle($spoke->id, (string) $user->id, CarbonImmutable::now());

    replaceSpokeRequest($this, $user, $new, $old['token'])
        ->assertOk()
        ->assertJsonPath('replaced', false);

    expect(Spoke::query()->count())->toBe(2)
        ->and($spoke->refresh()->retired_at)->not->toBeNull()
        ->and(Spoke::query()->whereNull('retired_at')->sole()->credential_id)->toBe($new['credential']->id);
});

test('two replacements authenticated before cutover leave one live credential bound to the original spoke', function (): void {
    $user = capstanUser();
    $old = capstanBoundBearer($user, CapstanCredentialDeclaration::POSTMASTER_POLL);
    $first = capstanBoundBearer($user, CapstanCredentialDeclaration::POSTMASTER_POLL);
    $second = capstanBoundBearer($user, CapstanCredentialDeclaration::POSTMASTER_POLL);
    $spoke = Spoke::query()->create([
        'actor_id' => (string) $user->id,
        'credential_id' => $old['credential']->id,
    ]);
    $firstOld = authenticatedReplacementCredential($user, $old['token']);
    $secondOld = authenticatedReplacementCredential($user, $old['token']);
    $service = resolve(ReplaceSpokeCredential::class);

    $service->handle((string) $user->id, $first['credential']->id, $firstOld, CarbonImmutable::now());
    $loser = $service->handle(
        (string) $user->id,
        $second['credential']->id,
        $secondOld,
        CarbonImmutable::now(),
    );

    expect($loser['conflict'])->toBeTrue()
        ->and($loser['spoke'])->toBeNull()
        ->and($spoke->refresh()->credential_id)->toBe($first['credential']->id)
        ->and($old['credential']->refresh()->revoked_at)->not->toBeNull()
        ->and($first['credential']->refresh()->revoked_at)->toBeNull()
        ->and($second['credential']->refresh()->revoked_at)->not->toBeNull()
        ->and(Spoke::query()->count())->toBe(1);
});
