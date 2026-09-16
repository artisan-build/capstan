<?php

use App\Auth\CapstanCredentialDeclaration;
use App\Enums\ArtifactVisibility;
use App\Features\Artifacts as ArtifactsFeature;
use App\Models\Artifact;
use App\Models\Team;
use ArtisanBuild\BuiltForCloud\ClientIdentity;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config(['capstan.features.artifacts' => true]);
    Feature::flushCache();
    Storage::fake();
});

/** @return array<string, string> */
function artifactHeadersFor(User $user, ?string $actorId = null): array
{
    return capstanBearerHeaders($user, CapstanCredentialDeclaration::ARTIFACT_INGEST, $actorId);
}

test('ingest with a valid token creates an artifact stores the blob and returns a share url', function (): void {
    $user = capstanUser();
    $content = '<!doctype html><html><body>Launch notes</body></html>';
    $hash = hash('sha256', $content);

    $this->withHeaders(artifactHeadersFor($user))
        ->postJson('/api/v1/artifacts', [
            'content' => $content,
            'content_type' => 'text/html',
            'visibility' => ArtifactVisibility::SignedUrl->value,
            'expires_at' => now()->addHour()->toISOString(),
        ])
        ->assertCreated()
        ->assertJsonPath('artifact.actor_id', (string) $user->id)
        ->assertJsonPath('artifact.visibility', ArtifactVisibility::SignedUrl->value)
        ->assertJsonPath('artifact.content_hash', $hash)
        ->assertJsonPath('artifact.size_bytes', strlen($content))
        ->assertJsonPath('artifact.content_type', 'text/html')
        ->assertJson(fn ($json) => $json->where('share_url', fn (string $url): bool => str_contains($url, '/artifacts/'.Artifact::query()->firstOrFail()->id.'/share') && str_contains($url, 'signature='))->etc())
        ->assertJsonMissingPath('artifact.storage_key');

    $artifact = Artifact::query()->firstOrFail();

    expect($artifact->actor_id)->toBe((string) $user->id)
        ->and($artifact->visibility)->toBe(ArtifactVisibility::SignedUrl)
        ->and($artifact->content_hash)->toBe($hash)
        ->and($artifact->storage_key)->toBe('artifacts/'.$hash);

    Storage::disk()->assertExists($artifact->storage_key);
});

test('ingest without or with a bad token returns the api error envelope', function (): void {
    $payload = ['content' => '<html></html>', 'content_type' => 'text/html'];

    $this->postJson('/api/v1/artifacts', $payload)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');

    $this->withToken('garbage')
        ->postJson('/api/v1/artifacts', $payload)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});

test('same content ingested twice stores one blob and records the sha256 hash', function (): void {
    $user = capstanUser();
    $headers = artifactHeadersFor($user);
    $content = '<!doctype html><html><body>Same payload</body></html>';
    $hash = hash('sha256', $content);

    $this->withHeaders($headers)->postJson('/api/v1/artifacts', [
        'content' => $content,
        'content_type' => 'text/html',
    ])->assertCreated();

    $this->withHeaders($headers)->postJson('/api/v1/artifacts', [
        'content' => $content,
        'content_type' => 'text/html',
    ])->assertCreated();

    expect(Storage::disk()->allFiles('artifacts'))->toBe(['artifacts/'.$hash])
        ->and(Artifact::query()->pluck('content_hash')->all())->toBe([$hash, $hash]);
});

test('every created artifact grants the creators default team', function (): void {
    $user = capstanUser();

    $this->withHeaders(artifactHeadersFor($user))->postJson('/api/v1/artifacts', [
        'content' => '<html><body>Grant me</body></html>',
        'content_type' => 'text/html',
    ])->assertCreated();

    $artifact = Artifact::query()->firstOrFail();

    $this->assertDatabaseHas('artifact_team', [
        'artifact_id' => $artifact->id,
        'team_id' => Team::default()->id,
    ]);
});

test('visibility is an enum and invalid visibility is rejected', function (): void {
    $user = capstanUser();

    $this->withHeaders(artifactHeadersFor($user))->postJson('/api/v1/artifacts', [
        'content' => '<html><body>Default visibility</body></html>',
        'content_type' => 'text/html',
    ])->assertCreated();

    expect(Artifact::query()->firstOrFail()->visibility)->toBe(ArtifactVisibility::OrgAuth);

    $this->withHeaders(artifactHeadersFor($user))->postJson('/api/v1/artifacts', [
        'content' => '<html><body>Bad visibility</body></html>',
        'content_type' => 'text/html',
        'visibility' => 'public_bucket',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonValidationErrors('visibility', 'error.errors');
});

test('oversized content and disallowed content types are rejected and not stored', function (): void {
    config(['capstan.artifacts.max_content_bytes' => 10]);
    $user = capstanUser();

    $this->withHeaders(artifactHeadersFor($user))->postJson('/api/v1/artifacts', [
        'content' => str_repeat('x', 11),
        'content_type' => 'text/html',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonValidationErrors('content', 'error.errors');

    $this->withHeaders(artifactHeadersFor($user))->postJson('/api/v1/artifacts', [
        'content' => '<html><body>Nope</body></html>',
        'content_type' => 'text/plain',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonValidationErrors('content_type', 'error.errors');

    expect(Storage::disk()->allFiles())->toBe([])
        ->and(Artifact::query()->count())->toBe(0);
});

test('artifact ingest is not usable when the feature flag is off', function (): void {
    config(['capstan.features.artifacts' => false]);
    Feature::flushCache();
    $user = capstanUser();

    $this->withHeaders(artifactHeadersFor($user))->postJson('/api/v1/artifacts', [
        'content' => '<html><body>Disabled</body></html>',
        'content_type' => 'text/html',
    ])->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    expect(Storage::disk()->allFiles())->toBe([])
        ->and(Artifact::query()->count())->toBe(0)
        ->and(Feature::active(ArtifactsFeature::class))->toBeFalse();
});

test('artifact ingest does not expose raw storage urls or aws configuration', function (): void {
    $user = capstanUser();
    $content = '<!doctype html><html><body>Private</body></html>';
    $hash = hash('sha256', $content);

    $response = $this->withHeaders(artifactHeadersFor($user))->postJson('/api/v1/artifacts', [
        'content' => $content,
        'content_type' => 'text/html',
    ])->assertCreated();

    expect($response->json('share_url'))->toContain('/artifacts/'.Artifact::query()->firstOrFail()->id.'/share')
        ->toContain('signature=')
        ->not->toContain('/storage')
        ->not->toContain($hash);

    $configFiles = collect(glob(config_path('*.php')) ?: [])
        ->map(fn (string $path): string => file_get_contents($path) ?: '')
        ->push(file_get_contents(base_path('.env.example')) ?: '')
        ->implode("\n");

    expect($configFiles)->not->toContain('AWS_');
});

test('artifact ingest binds the bearer to the independently presented actor before validation or effects', function (): void {
    $owner = capstanUser();
    $other = capstanUser();
    $issued = capstanBoundBearer($owner, CapstanCredentialDeclaration::ARTIFACT_INGEST);
    $headers = [
        'Authorization' => 'Bearer '.$issued['token'],
        CapstanCredentialDeclaration::ACTOR_HEADER => (string) $other->getKey(),
        ClientIdentity::HEADER => 'capstan-test/1.0',
    ];

    $this->withHeaders($headers)
        ->postJson('/api/v1/artifacts', [])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated')
        ->assertJsonMissingPath('error.errors');

    $credential = $issued['credential']->refresh();
    expect($credential->last_used_at)->toBeNull()
        ->and($credential->client_identity)->toBeNull()
        ->and(Artifact::query()->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBe([]);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$issued['token'],
        CapstanCredentialDeclaration::ACTOR_HEADER => (string) $owner->getKey(),
        ClientIdentity::HEADER => 'capstan-test/1.0',
    ])->postJson('/api/v1/artifacts', [
        'content' => '<html><body>Matching subject</body></html>',
        'content_type' => 'text/html',
    ])->assertCreated()->assertJsonPath('artifact.actor_id', (string) $owner->getKey());

    expect($issued['credential']->refresh()->client_identity)->toBe('capstan-test/1.0');
});
