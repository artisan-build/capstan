<?php

use App\Enums\DeviceCodeStatus;
use App\Models\DeviceCode;
use App\Models\User;
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
