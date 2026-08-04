<?php

use App\Models\AuthorizationCode;
use App\Models\User;
use Tests\TestCase;

/** @return array{code: string, redirect_uri: string} */
function approveLoopbackAuthorization(TestCase $test, User $user, string $redirectUri, ?string $label = null): array
{
    $response = $test->actingAs($user)
        ->post('/cli/authorize', [
            'redirect_uri' => $redirectUri,
            'state' => 'exchange-state',
            'label' => $label,
            'action' => 'approve',
        ])
        ->assertRedirect();

    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query['code'] ?? null)->toBeString()->not->toBeEmpty();

    return ['code' => $query['code'], 'redirect_uri' => $redirectUri];
}

test('approve redirect carries a code and never a personal access token', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post('/cli/authorize', [
            'redirect_uri' => 'http://127.0.0.1:49152/callback',
            'state' => 'state-otc',
            'action' => 'approve',
        ])
        ->assertRedirect();

    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect(array_keys($query))->toBe(['code', 'state'])
        ->and($location)->not->toContain('token=');

    expect($user->tokens()->count())->toBe(0);
});

test('exchanging a code returns a working personal access token', function (): void {
    $user = User::factory()->create();
    ['code' => $code, 'redirect_uri' => $redirectUri] = approveLoopbackAuthorization($this, $user, 'http://127.0.0.1:49152/callback');

    $token = $this->postJson('/api/v1/cli/authorize/token', [
        'code' => $code,
        'redirect_uri' => $redirectUri,
    ])
        ->assertOk()
        ->assertJsonStructure(['token'])
        ->json('token');

    $this->withToken($token)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('id', $user->id);
});

test('a code is single use', function (): void {
    $user = User::factory()->create();
    ['code' => $code, 'redirect_uri' => $redirectUri] = approveLoopbackAuthorization($this, $user, 'http://127.0.0.1:49152/callback');

    $this->postJson('/api/v1/cli/authorize/token', [
        'code' => $code,
        'redirect_uri' => $redirectUri,
    ])->assertOk();

    $this->postJson('/api/v1/cli/authorize/token', [
        'code' => $code,
        'redirect_uri' => $redirectUri,
    ])
        ->assertBadRequest()
        ->assertJsonPath('error.code', 'invalid_grant')
        ->assertJsonMissingPath('token');

    expect($user->tokens()->count())->toBe(1);
});

test('an expired code cannot be exchanged', function (): void {
    $user = User::factory()->create();
    ['code' => $code, 'redirect_uri' => $redirectUri] = approveLoopbackAuthorization($this, $user, 'http://127.0.0.1:49152/callback');

    $this->travel(AuthorizationCode::LIFETIME_SECONDS + 1)->seconds();

    $this->postJson('/api/v1/cli/authorize/token', [
        'code' => $code,
        'redirect_uri' => $redirectUri,
    ])
        ->assertBadRequest()
        ->assertJsonPath('error.code', 'expired_token')
        ->assertJsonMissingPath('token');

    expect($user->tokens()->count())->toBe(0);
});

test('a code is bound to the redirect uri that requested it', function (): void {
    $user = User::factory()->create();
    ['code' => $code] = approveLoopbackAuthorization($this, $user, 'http://127.0.0.1:49152/callback');

    $this->postJson('/api/v1/cli/authorize/token', [
        'code' => $code,
        'redirect_uri' => 'http://127.0.0.1:49153/callback',
    ])
        ->assertBadRequest()
        ->assertJsonPath('error.code', 'invalid_grant')
        ->assertJsonMissingPath('token');

    expect($user->tokens()->count())->toBe(0);
});

test('an unknown code cannot be exchanged', function (): void {
    $this->postJson('/api/v1/cli/authorize/token', [
        'code' => 'not-a-real-code',
        'redirect_uri' => 'http://127.0.0.1:49152/callback',
    ])
        ->assertBadRequest()
        ->assertJsonPath('error.code', 'invalid_grant')
        ->assertJsonMissingPath('token');
});

test('the exchange endpoint validates its input', function (): void {
    $this->postJson('/api/v1/cli/authorize/token', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code', 'redirect_uri']);
});

test('only a hash of the code is stored', function (): void {
    $user = User::factory()->create();
    ['code' => $code] = approveLoopbackAuthorization($this, $user, 'http://127.0.0.1:49152/callback');

    $record = AuthorizationCode::query()->sole();

    expect($record->code_hash)->toBe(hash('sha256', $code))
        ->not->toBe($code);

    $this->assertDatabaseMissing('cli_authorization_codes', ['code_hash' => $code]);
});

test('a code stores the sanitized label and the exchanged token uses it', function (): void {
    $user = User::factory()->create();
    ['code' => $code, 'redirect_uri' => $redirectUri] = approveLoopbackAuthorization($this, $user, 'http://127.0.0.1:49152/callback', "  Build Box\n ");

    expect(AuthorizationCode::query()->sole()->label)->toBe('Build Box');

    $this->postJson('/api/v1/cli/authorize/token', [
        'code' => $code,
        'redirect_uri' => $redirectUri,
    ])->assertOk();

    $this->assertDatabaseHas('personal_access_tokens', [
        'tokenable_id' => $user->id,
        'name' => 'capstan-cli — Build Box',
    ]);
});

test('a code whose user was deleted cannot be exchanged', function (): void {
    $user = User::factory()->create();
    ['code' => $code, 'redirect_uri' => $redirectUri] = approveLoopbackAuthorization($this, $user, 'http://127.0.0.1:49152/callback');

    $user->delete();

    $this->postJson('/api/v1/cli/authorize/token', [
        'code' => $code,
        'redirect_uri' => $redirectUri,
    ])
        ->assertBadRequest()
        ->assertJsonPath('error.code', 'invalid_grant')
        ->assertJsonMissingPath('token');
});

test('expired authorization codes are pruned', function (): void {
    $expired = AuthorizationCode::factory()->create(['expires_at' => now()->subSecond()]);
    $live = AuthorizationCode::factory()->create();

    $this->artisan('capstan:prune-device-codes')
        ->expectsOutput('Pruned 1 authorization code(s).')
        ->assertSuccessful();

    $this->assertDatabaseMissing('cli_authorization_codes', ['id' => $expired->id]);
    $this->assertDatabaseHas('cli_authorization_codes', ['id' => $live->id]);
});
