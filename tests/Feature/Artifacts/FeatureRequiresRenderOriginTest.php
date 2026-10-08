<?php

use App\Auth\CapstanCredentialDeclaration;
use App\Enums\ArtifactVisibility;
use App\Features\Artifacts as ArtifactsFeature;
use App\Models\Artifact;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'app.url' => 'https://app.capstan.test',
        'capstan.features.artifacts' => true,
        'capstan.artifacts.render_origin' => 'https://artifacts.capstan.test',
    ]);
    Feature::flushCache();
    Storage::fake();
});

/**
 * Every case below sets both keys explicitly rather than leaning on `.env`:
 * the feature now resolves from two config values, so a test that names only
 * one of them is a test whose result depends on the developer's environment.
 */
function originConfig(?string $renderOrigin, bool $flag = true, string $appUrl = 'https://app.capstan.test'): void
{
    config([
        'app.url' => $appUrl,
        'capstan.features.artifacts' => $flag,
        'capstan.artifacts.render_origin' => $renderOrigin,
    ]);
    Feature::flushCache();
}

/** @return array<string, string> */
function ingestHeaders(): array
{
    return capstanBearerHeaders(capstanUser(), CapstanCredentialDeclaration::ARTIFACT_INGEST);
}

function postArtifact(): Illuminate\Testing\TestResponse
{
    return test()->withHeaders(ingestHeaders())->postJson('/api/v1/artifacts', [
        'content' => '<html><body>ingest attempt</body></html>',
        'content_type' => 'text/html',
        'visibility' => ArtifactVisibility::SignedUrl->value,
    ]);
}

test('the feature is inactive when the render origin is the app host', function (): void {
    originConfig('https://app.capstan.test');

    expect(Feature::active(ArtifactsFeature::class))->toBeFalse();
});

test('the feature is inactive when the render origin is unset', function (): void {
    originConfig(null);

    expect(Feature::active(ArtifactsFeature::class))->toBeFalse();
});

test('the feature is active when the flag is on and the render origin is a separate host', function (): void {
    originConfig('https://artifacts.capstan.test');

    expect(Feature::active(ArtifactsFeature::class))->toBeTrue();
});

test('the flag remains an independent switch: off stays off with a good render origin', function (): void {
    originConfig('https://artifacts.capstan.test', flag: false);

    expect(Feature::active(ArtifactsFeature::class))->toBeFalse();
});

test('the feature is inactive when the render origin matches the app host only after normalisation', function (): void {
    originConfig('https://app.capstan.test.', appUrl: 'https://App.Capstan.Test');

    expect(Feature::active(ArtifactsFeature::class))->toBeFalse();
});

test('ingest is refused when the render origin is the app host and nothing is stored', function (): void {
    originConfig('https://app.capstan.test');

    postArtifact()
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    expect(Artifact::query()->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBe([]);
});

test('ingest is refused when the render origin is unset and nothing is stored', function (): void {
    originConfig(null);

    postArtifact()
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    expect(Artifact::query()->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBe([]);
});

test('ingest still works when the flag is on and the render origin is a separate host', function (): void {
    originConfig('https://artifacts.capstan.test');

    postArtifact()->assertCreated();

    expect(Artifact::query()->count())->toBe(1)
        ->and(Storage::disk()->allFiles())->toHaveCount(1);
});
