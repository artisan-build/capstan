<?php

use App\Enums\ArtifactVisibility;
use App\Features\Artifacts as ArtifactsFeature;
use App\Models\Artifact;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config(['capstan.features.artifacts' => true]);
    Feature::flushCache();
    Storage::fake();
});

function artifactTokenFor(User $user): string
{
    // Tokens are unscoped today; ability-gated ingest is a later design decision.
    return $user->createToken('capstan-cli')->plainTextToken;
}

test('ingest with a valid token creates an artifact stores the blob and returns a share url', function (): void {
    $user = User::factory()->create();
    $content = '<!doctype html><html><body>Launch notes</body></html>';
    $hash = hash('sha256', $content);

    $this->withToken(artifactTokenFor($user))
        ->postJson('/api/v1/artifacts', [
            'content' => $content,
            'content_type' => 'text/html',
            'visibility' => ArtifactVisibility::SignedUrl->value,
            'expires_at' => now()->addHour()->toISOString(),
        ])
        ->assertCreated()
        ->assertJsonPath('artifact.author_id', $user->id)
        ->assertJsonPath('artifact.visibility', ArtifactVisibility::SignedUrl->value)
        ->assertJsonPath('artifact.content_hash', $hash)
        ->assertJsonPath('artifact.size_bytes', strlen($content))
        ->assertJsonPath('artifact.content_type', 'text/html')
        ->assertJsonPath('share_url', route('artifacts.share', ['artifact' => Artifact::query()->firstOrFail()]))
        ->assertJsonMissingPath('artifact.storage_key');

    $artifact = Artifact::query()->firstOrFail();

    expect($artifact->author_id)->toBe($user->id)
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
    $user = User::factory()->create();
    $token = artifactTokenFor($user);
    $content = '<!doctype html><html><body>Same payload</body></html>';
    $hash = hash('sha256', $content);

    $this->withToken($token)->postJson('/api/v1/artifacts', [
        'content' => $content,
        'content_type' => 'text/html',
    ])->assertCreated();

    $this->withToken($token)->postJson('/api/v1/artifacts', [
        'content' => $content,
        'content_type' => 'text/html',
    ])->assertCreated();

    expect(Storage::disk()->allFiles('artifacts'))->toBe(['artifacts/'.$hash])
        ->and(Artifact::query()->pluck('content_hash')->all())->toBe([$hash, $hash]);
});

test('every created artifact grants the creators default team', function (): void {
    $user = User::factory()->create();

    $this->withToken(artifactTokenFor($user))->postJson('/api/v1/artifacts', [
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
    $user = User::factory()->create();

    $this->withToken(artifactTokenFor($user))->postJson('/api/v1/artifacts', [
        'content' => '<html><body>Default visibility</body></html>',
        'content_type' => 'text/html',
    ])->assertCreated();

    expect(Artifact::query()->firstOrFail()->visibility)->toBe(ArtifactVisibility::OrgAuth);

    $this->withToken(artifactTokenFor($user))->postJson('/api/v1/artifacts', [
        'content' => '<html><body>Bad visibility</body></html>',
        'content_type' => 'text/html',
        'visibility' => 'public_bucket',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonValidationErrors('visibility', 'error.errors');
});

test('oversized content and disallowed content types are rejected and not stored', function (): void {
    config(['capstan.artifacts.max_content_bytes' => 10]);
    $user = User::factory()->create();

    $this->withToken(artifactTokenFor($user))->postJson('/api/v1/artifacts', [
        'content' => str_repeat('x', 11),
        'content_type' => 'text/html',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonValidationErrors('content', 'error.errors');

    $this->withToken(artifactTokenFor($user))->postJson('/api/v1/artifacts', [
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
    $user = User::factory()->create();

    $this->withToken(artifactTokenFor($user))->postJson('/api/v1/artifacts', [
        'content' => '<html><body>Disabled</body></html>',
        'content_type' => 'text/html',
    ])->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    expect(Storage::disk()->allFiles())->toBe([])
        ->and(Artifact::query()->count())->toBe(0)
        ->and(Feature::active(ArtifactsFeature::class))->toBeFalse();
});

test('artifact ingest does not expose raw storage urls or aws configuration', function (): void {
    $user = User::factory()->create();
    $content = '<!doctype html><html><body>Private</body></html>';
    $hash = hash('sha256', $content);

    $response = $this->withToken(artifactTokenFor($user))->postJson('/api/v1/artifacts', [
        'content' => $content,
        'content_type' => 'text/html',
    ])->assertCreated();

    expect($response->json('share_url'))->toBe(route('artifacts.share', ['artifact' => Artifact::query()->firstOrFail()]))
        ->not->toContain('/storage')
        ->not->toContain($hash);

    $configFiles = collect(glob(config_path('*.php')) ?: [])
        ->map(fn (string $path): string => file_get_contents($path) ?: '')
        ->push(file_get_contents(base_path('.env.example')) ?: '')
        ->implode("\n");

    expect($configFiles)->not->toContain('AWS_');
});
