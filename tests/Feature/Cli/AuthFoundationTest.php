<?php

use App\Enums\DeviceCodeStatus;
use App\Http\ApiActor;
use App\Models\DeviceCode;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;

test('loopback authorize requires a web session', function (): void {
    $this->get('/cli/authorize?redirect_uri=http://127.0.0.1:49152/callback')
        ->assertRedirect('/login');
});

test('loopback authorize rejects non-loopback redirect uri on both legs', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/cli/authorize?redirect_uri=https://127.0.0.1:49152/callback')
        ->assertUnprocessable();

    $this->actingAs($user)
        ->post('/cli/authorize', [
            'redirect_uri' => 'http://example.com/callback',
            'state' => 'state-1',
            'action' => 'approve',
        ])
        ->assertUnprocessable();
});

test('loopback authorize rejects parser-confusion and non-loopback payloads on both legs', function (string $redirectUri): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/cli/authorize?'.http_build_query(['redirect_uri' => $redirectUri]))
        ->assertUnprocessable();

    $this->actingAs($user)
        ->post('/cli/authorize', [
            'redirect_uri' => $redirectUri,
            'state' => 'state-hostile',
            'action' => 'approve',
        ])
        ->assertUnprocessable();
})->with([
    'backslash before allowed host' => ['http://evil.example\@127.0.0.1:49152/cb'],
    'encoded backslash before allowed host' => ['http://evil.example%5C@127.0.0.1/cb'],
    'userinfo before allowed host' => ['http://user@127.0.0.1/cb'],
    'localhost suffix' => ['http://localhost.evil.com/cb'],
    'https loopback' => ['https://127.0.0.1/cb'],
    'ipv4 suffix' => ['http://127.0.0.1.evil.com/cb'],
    'scheme-relative loopback' => ['//127.0.0.1/cb'],
]);

test('loopback authorize still accepts a valid loopback control', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/cli/authorize?'.http_build_query(['redirect_uri' => 'http://127.0.0.1:49152/cb']))
        ->assertOk()
        ->assertSee('127.0.0.1:49152');
});

test('loopback authorize allows at signs in query and fragment outside the authority', function (string $redirectUri): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/cli/authorize?'.http_build_query(['redirect_uri' => $redirectUri]))
        ->assertOk();
})->with([
    'query email' => ['http://127.0.0.1:8080/cb?x=a@b.com'],
    'fragment at sign' => ['http://127.0.0.1:8080/cb#a@b'],
]);

test('loopback authorize mints a token that authenticates the api', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post('/cli/authorize', [
            'redirect_uri' => 'http://localhost:49152/callback?existing=1',
            'state' => 'state-2',
            'label' => "  My Laptop\n ",
            'action' => 'approve',
        ])
        ->assertRedirect();

    $location = $response->headers->get('Location');
    expect($location)->toBeString()->toContain('http://localhost:49152/callback?existing=1&');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query['state'])->toBe('state-2')
        ->and($query['token'])->toBeString()->not->toBeEmpty();

    $this->withToken($query['token'])
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJson([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ]);

    $this->assertDatabaseHas('personal_access_tokens', [
        'tokenable_id' => $user->id,
        'name' => 'capstan-cli — My Laptop',
    ]);
});

test('loopback authorize appends token before a fragment', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post('/cli/authorize', [
            'redirect_uri' => 'http://127.0.0.1:49152/callback#frag',
            'state' => 'state-frag',
            'action' => 'approve',
        ])
        ->assertRedirect();

    $location = $response->headers->get('Location');
    expect($location)->toBeString()
        ->toStartWith('http://127.0.0.1:49152/callback?')
        ->toEndWith('#frag')
        ->and($location)->toContain('token=')
        ->and($location)->toContain('state=state-frag');
});

test('loopback authorize preserves existing query parameters when appending token', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post('/cli/authorize', [
            'redirect_uri' => 'http://127.0.0.1:49152/callback?existing=1',
            'state' => 'state-query',
            'action' => 'approve',
        ])
        ->assertRedirect();

    $location = $response->headers->get('Location');
    expect($location)->toBeString()
        ->toStartWith('http://127.0.0.1:49152/callback?existing=1&')
        ->and($location)->toContain('token=')
        ->and($location)->toContain('state=state-query');
});

test('loopback deny redirects with access denied and does not mint a token', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post('/cli/authorize', [
            'redirect_uri' => 'http://127.0.0.1:49152/callback',
            'state' => 'state-3',
            'action' => 'deny',
        ])
        ->assertRedirect();

    $location = $response->headers->get('Location');
    expect($location)->toBeString();

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query)->toMatchArray([
        'error' => 'access_denied',
        'state' => 'state-3',
    ])->and($query)->not->toHaveKey('token');

    expect($user->tokens()->count())->toBe(0);
});

test('device flow happy path mints one token and consumes the grant', function (): void {
    $user = User::factory()->create();

    $created = $this->postJson('/api/v1/cli/device', ['label' => 'Rack terminal'])
        ->assertOk()
        ->assertJsonStructure([
            'device_code',
            'user_code',
            'verification_uri',
            'verification_uri_complete',
            'interval',
            'expires_in',
        ])
        ->json();

    $this->actingAs($user)
        ->post('/cli/device', [
            'user_code' => $created['user_code'],
            'action' => 'approve',
        ])
        ->assertOk()
        ->assertSee('Device authorized');

    $token = $this->postJson('/api/v1/cli/device/token', ['device_code' => $created['device_code']])
        ->assertOk()
        ->assertJsonStructure(['token'])
        ->json('token');

    $this->postJson('/api/v1/cli/device/token', ['device_code' => $created['device_code']])
        ->assertBadRequest()
        ->assertJsonPath('error.code', 'invalid_grant');

    $this->withToken($token)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('id', $user->id);
});

test('device token polling returns pending and slow down', function (): void {
    $created = $this->postJson('/api/v1/cli/device')->json();

    $this->postJson('/api/v1/cli/device/token', ['device_code' => $created['device_code']])
        ->assertBadRequest()
        ->assertJsonPath('error.code', 'authorization_pending');

    $this->postJson('/api/v1/cli/device/token', ['device_code' => $created['device_code']])
        ->assertBadRequest()
        ->assertJsonPath('error.code', 'slow_down');
});

test('device token polling returns expired and access denied', function (): void {
    ['device_code' => $expiredCode, 'model' => $expired] = DeviceCode::issue();
    $expired->forceFill(['expires_at' => now()->subSecond()])->save();

    $this->postJson('/api/v1/cli/device/token', ['device_code' => $expiredCode])
        ->assertBadRequest()
        ->assertJsonPath('error.code', 'expired_token');

    ['device_code' => $deniedCode, 'model' => $denied] = DeviceCode::issue();
    $denied->forceFill(['status' => DeviceCodeStatus::Denied])->save();

    $this->postJson('/api/v1/cli/device/token', ['device_code' => $deniedCode])
        ->assertBadRequest()
        ->assertJsonPath('error.code', 'access_denied');
});

test('approving unknown or expired user code fails', function (): void {
    $user = User::factory()->create();
    $expired = DeviceCode::factory()->create(['expires_at' => now()->subSecond()]);

    $this->actingAs($user)
        ->post('/cli/device', [
            'user_code' => 'XXXX-XXXX',
            'action' => 'approve',
        ])
        ->assertOk()
        ->assertSee('invalid, has expired, or was already used');

    $this->actingAs($user)
        ->post('/cli/device', [
            'user_code' => $expired->user_code,
            'action' => 'approve',
        ])
        ->assertOk()
        ->assertSee('invalid, has expired, or was already used');

    expect($expired->refresh()->status)->toBe(DeviceCodeStatus::Pending);
});

test('device code table stores no plaintext device code', function (): void {
    $created = $this->postJson('/api/v1/cli/device')->json();
    $device = DeviceCode::query()->where('user_code', $created['user_code'])->firstOrFail();

    expect($device->device_code_hash)->not->toBe($created['device_code'])
        ->and($device->device_code_hash)->toBe(hash('sha256', $created['device_code']));
});

test('api actor middleware returns envelopes for missing garbage and expired tokens', function (): void {
    $this->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');

    $this->withToken('garbage')
        ->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');

    $user = User::factory()->create();
    $newToken = $user->createToken('capstan-cli');
    $newToken->accessToken->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->withToken($newToken->plainTextToken)
        ->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});

test('api actor middleware authenticates valid tokens', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('capstan-cli')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJson([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ]);
});

test('api actor middleware stamps last used at without hot-rowing', function (): void {
    $user = User::factory()->create();
    $newToken = $user->createToken('capstan-cli');

    $this->withToken($newToken->plainTextToken)
        ->getJson('/api/v1/me')
        ->assertOk();

    $accessToken = $newToken->accessToken->refresh();
    expect($accessToken->last_used_at)->not->toBeNull();

    $recentlyUsedAt = now()->subSeconds(30)->startOfSecond();
    $accessToken->forceFill(['last_used_at' => $recentlyUsedAt])->save();

    $this->withToken($newToken->plainTextToken)
        ->getJson('/api/v1/me')
        ->assertOk();

    expect($accessToken->refresh()->last_used_at?->toDateTimeString())->toBe($recentlyUsedAt->toDateTimeString());
});

test('api actor middleware attaches the current access token to the user', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('capstan-cli', ['artifact:ingest'])->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/me')
        ->assertOk();

    expect(auth()->user()?->currentAccessToken())->toBeInstanceOf(PersonalAccessToken::class)
        ->and(auth()->user()?->tokenCan('artifact:ingest'))->toBeTrue();
});

test('tokens page lists only current user tokens and revokes them', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $token = $user->createToken('capstan-cli')->plainTextToken;
    $userTokenId = $user->tokens()->firstOrFail()->id;
    $other->createToken('capstan-cli — Other machine');
    $otherTokenId = $other->tokens()->firstOrFail()->id;

    $this->actingAs($user)
        ->get('/settings/tokens')
        ->assertOk()
        ->assertSee('capstan-cli')
        ->assertDontSee('Other machine');

    Livewire::actingAs($user)
        ->test('settings.tokens')
        ->call('revoke', $userTokenId)
        ->assertOk();

    $this->withToken($token)
        ->getJson('/api/v1/me')
        ->assertUnauthorized();

    Livewire::actingAs($user)
        ->test('settings.tokens')
        ->call('revoke', $otherTokenId)
        ->assertForbidden();

    expect(PersonalAccessToken::query()->whereKey($otherTokenId)->exists())->toBeTrue();
});

test('device code pruning keeps recent denied rows pollable and removes stale denied rows', function (): void {
    $expired = DeviceCode::factory()->create(['expires_at' => now()->subSecond()]);
    $recentDenied = DeviceCode::factory()->create([
        'status' => DeviceCodeStatus::Denied,
        'expires_at' => now()->addMinute(),
        'updated_at' => now(),
    ]);
    $staleDenied = DeviceCode::factory()->create([
        'status' => DeviceCodeStatus::Denied,
        'expires_at' => now()->addMinute(),
        'updated_at' => now()->subSeconds(DeviceCode::LIFETIME_SECONDS + 1),
    ]);

    $this->artisan('capstan:prune-device-codes')
        ->expectsOutput('Pruned 2 device code(s).')
        ->assertSuccessful();

    $this->assertDatabaseMissing('device_codes', ['id' => $expired->id]);
    $this->assertDatabaseHas('device_codes', ['id' => $recentDenied->id]);
    $this->assertDatabaseMissing('device_codes', ['id' => $staleDenied->id]);
});

test('device code pruning is scheduled hourly', function (): void {
    $events = app(Schedule::class)->events();

    $scheduled = collect($events)->contains(function ($event): bool {
        return str_contains($event->command, 'capstan:prune-device-codes')
            && $event->expression === '0 * * * *';
    });

    expect($scheduled)->toBeTrue();
});

test('cli rate limiters have the expected budgets', function (): void {
    $deviceLimiter = RateLimiter::limiter('cli-device');
    $verifyLimiter = RateLimiter::limiter('cli-verify');

    $deviceLimit = $deviceLimiter(Request::create('/api/v1/cli/device', 'POST', [], [], [], ['REMOTE_ADDR' => '192.0.2.10']));
    $verifyRequest = Request::create('/cli/device', 'POST', [], [], [], ['REMOTE_ADDR' => '192.0.2.20']);
    $verifyRequest->setUserResolver(fn (): User => User::factory()->create());
    $verifyLimit = $verifyLimiter($verifyRequest);

    expect($deviceLimit->maxAttempts)->toBe(15)
        ->and($verifyLimit->maxAttempts)->toBe(10);
});

test('api rate limiter keys on resolved actor or ip instead of raw bearer', function (): void {
    $limiter = RateLimiter::limiter('api');

    $actorRequest = Request::create('/api/v1/me', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.30']);
    $actorRequest->headers->set('Authorization', 'Bearer attacker-controlled');
    $actorRequest->attributes->set('api_actor', ApiActor::user(123));

    $ipRequest = Request::create('/api/v1/me', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.31']);
    $ipRequest->headers->set('Authorization', 'Bearer another-attacker-controlled');

    expect($limiter($actorRequest)->key)->toBe('user:123')
        ->and($limiter($ipRequest)->key)->toBe('ip:192.0.2.31');
});

test('trusted proxies ignore forged forwarded host when generating urls', function (): void {
    Route::get('/_proxy-url-test', fn () => response()->json([
        'url' => url('/password/reset/test-token'),
    ]));

    $response = $this->call('GET', '/_proxy-url-test', [], [], [], [
        'HTTP_HOST' => 'capstan.test',
        'HTTP_X_FORWARDED_HOST' => 'evil.example',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_PORT' => '443',
        'HTTP_ACCEPT' => 'application/json',
    ])->assertOk();

    expect(parse_url($response->json('url'), PHP_URL_HOST))->not->toBe('evil.example');
});
