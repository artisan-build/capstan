<?php

use App\Enums\ArtifactVisibility;
use App\Models\Artifact;
use App\Support\ArtifactRenderOrigin;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'app.url' => 'https://app.test',
        'capstan.features.artifacts' => true,
    ]);
    Feature::flushCache();
    Storage::fake();
});

function sameHostArtifact(string $content, ArtifactVisibility $visibility = ArtifactVisibility::SignedUrl): Artifact
{
    [$contentHash, $storageKey] = Artifact::storeBlob($content);

    return Artifact::factory()->create([
        'visibility' => $visibility,
        'expires_at' => now()->addHour(),
        'content_type' => 'text/html',
        'size_bytes' => strlen($content),
        'content_hash' => $contentHash,
        'storage_key' => $storageKey,
    ]);
}

/**
 * The content route lives on the render host, so its URL cannot be built from
 * the helper under test. Sign the path and request it on the app host directly.
 */
function sameHostContentUrl(Artifact $artifact, string $origin = 'https://app.test'): string
{
    return $origin.URL::temporarySignedRoute(
        'artifacts.content',
        now()->addMinutes(5),
        ['artifact' => $artifact],
        false,
    );
}

test('a render origin on the app host counts as not configured', function (): void {
    config(['capstan.artifacts.render_origin' => 'https://app.test']);

    expect(resolve(ArtifactRenderOrigin::class)->isConfigured())->toBeFalse();
});

test('the app serves normally when the render origin is the app host', function (): void {
    config(['capstan.artifacts.render_origin' => 'https://app.test']);

    $this->get('https://app.test/')->assertOk();
    $this->get('https://app.test/up')->assertOk();
    $this->get('https://app.test/bfc/login')->assertOk();
});

test('artifact content is never served on the app origin when the two hosts match', function (): void {
    config(['capstan.artifacts.render_origin' => 'https://app.test']);
    $content = '<html><body>same host blob</body></html>';
    $artifact = sameHostArtifact($content);

    $this->get(sameHostContentUrl($artifact))
        ->assertNotFound()
        ->assertDontSee('same host blob');

    $this->get("https://app.test/artifacts/{$artifact->id}/content")
        ->assertNotFound()
        ->assertDontSee('same host blob');
});

test('the share viewer is unreachable when the two hosts match', function (): void {
    config(['capstan.artifacts.render_origin' => 'https://app.test']);
    $artifact = sameHostArtifact('<html><body>same host viewer</body></html>');

    $this->get(resolve(ArtifactRenderOrigin::class)->signedViewerUrl($artifact))
        ->assertNotFound()
        ->assertDontSee('sandbox="allow-scripts"', false);
});

test('the host comparison ignores case', function (): void {
    config([
        'app.url' => 'https://App.Test',
        'capstan.artifacts.render_origin' => 'https://app.test',
    ]);
    $artifact = sameHostArtifact('<html><body>cased blob</body></html>');

    expect(resolve(ArtifactRenderOrigin::class)->isConfigured())->toBeFalse();

    $this->get('https://app.test/')->assertOk();
    $this->get(sameHostContentUrl($artifact))->assertNotFound()->assertDontSee('cased blob');
});

test('the host comparison ignores a trailing dot', function (): void {
    config([
        'app.url' => 'https://app.test.',
        'capstan.artifacts.render_origin' => 'https://app.test',
    ]);
    $artifact = sameHostArtifact('<html><body>rooted blob</body></html>');

    expect(resolve(ArtifactRenderOrigin::class)->isConfigured())->toBeFalse();

    $this->get('https://app.test/')->assertOk();
    $this->get(sameHostContentUrl($artifact))->assertNotFound()->assertDontSee('rooted blob');
});

test('the host comparison ignores differing ports', function (): void {
    config([
        'app.url' => 'https://app.test:8443',
        'capstan.artifacts.render_origin' => 'https://app.test:9443',
    ]);
    $artifact = sameHostArtifact('<html><body>ported blob</body></html>');

    expect(resolve(ArtifactRenderOrigin::class)->isConfigured())->toBeFalse();

    $this->get('https://app.test:8443/')->assertOk();
    $this->get(sameHostContentUrl($artifact, 'https://app.test:9443'))
        ->assertNotFound()
        ->assertDontSee('ported blob');
});

test('an unset render origin leaves the app intact and artifact routes closed', function (): void {
    config(['capstan.artifacts.render_origin' => null]);
    $artifact = sameHostArtifact('<html><body>unset blob</body></html>');

    expect(resolve(ArtifactRenderOrigin::class)->isConfigured())->toBeFalse();

    $this->get('https://app.test/')->assertOk();
    $this->get(resolve(ArtifactRenderOrigin::class)->signedViewerUrl($artifact))->assertNotFound();
    $this->get(sameHostContentUrl($artifact))->assertNotFound()->assertDontSee('unset blob');
});

test('a render origin on a different host stays configured', function (): void {
    config(['capstan.artifacts.render_origin' => 'https://render.test']);

    expect(resolve(ArtifactRenderOrigin::class)->isConfigured())->toBeTrue();
});
