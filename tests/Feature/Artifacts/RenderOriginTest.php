<?php

use App\Enums\ArtifactVisibility;
use App\Models\Artifact;
use App\Models\Team;
use App\Models\User;
use App\Support\ArtifactRenderOrigin;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config([
        'app.url' => 'https://app.capstan.test',
        'capstan.features.artifacts' => true,
        'capstan.artifacts.render_origin' => 'https://artifacts.capstan.test',
    ]);
    Feature::flushCache();
    Storage::fake();
});

function storedArtifact(string $content, ArtifactVisibility $visibility = ArtifactVisibility::OrgAuth, ?DateTimeInterface $expiresAt = null): Artifact
{
    [$contentHash, $storageKey] = Artifact::storeBlob($content);

    return Artifact::factory()->create([
        'visibility' => $visibility,
        'expires_at' => $expiresAt,
        'content_type' => 'text/html',
        'size_bytes' => strlen($content),
        'content_hash' => $contentHash,
        'storage_key' => $storageKey,
    ]);
}

test('signed url mode streams byte-identical content with strict headers from render origin', function (): void {
    $content = '<!doctype html><html><body><script>window.x = "raw";</script></body></html>';
    $artifact = storedArtifact($content, ArtifactVisibility::SignedUrl, now()->addHour());
    $url = app(ArtifactRenderOrigin::class)->signedContentUrl($artifact);

    $response = $this->get($url)
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertHeader('Content-Type', 'text/html; charset=utf-8')
        ->assertStreamed();

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("connect-src 'none'")
        ->toContain('frame-ancestors https://app.capstan.test')
        ->and($response->baseResponse->isRedirection())->toBeFalse()
        ->and($response->streamedContent())->toBe($content);
});

test('tampered and expired signed urls are refused without content', function (): void {
    $content = '<html><body>secret signed artifact</body></html>';
    $artifact = storedArtifact($content, ArtifactVisibility::SignedUrl, now()->addHour());
    $url = app(ArtifactRenderOrigin::class)->signedContentUrl($artifact);

    $this->get($url.'&tampered=1')
        ->assertForbidden()
        ->assertDontSee('secret signed artifact');

    $expired = storedArtifact('<html><body>expired signed artifact</body></html>', ArtifactVisibility::SignedUrl, now()->subMinute());
    $expiredUrl = URL::temporarySignedRoute('artifacts.content', now()->addHour(), ['artifact' => $expired], false);

    $this->get('https://artifacts.capstan.test'.$expiredUrl)
        ->assertNotFound()
        ->assertDontSee('expired signed artifact');
});

test('org auth mode requires a granted team and refuses guests or ungranted users', function (): void {
    $content = '<html><body>org gated artifact</body></html>';
    $artifact = storedArtifact($content, ArtifactVisibility::OrgAuth, now()->addHour());
    $team = Team::default();
    $artifact->teams()->sync([$team->id]);
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $outsider->teams()->detach($team->id);

    $this->get("https://artifacts.capstan.test/artifacts/{$artifact->id}/content")
        ->assertForbidden()
        ->assertDontSee('org gated artifact');

    $this->actingAs($outsider)
        ->get("https://artifacts.capstan.test/artifacts/{$artifact->id}/content")
        ->assertForbidden()
        ->assertDontSee('org gated artifact');

    $response = $this->actingAs($member)
        ->get("https://artifacts.capstan.test/artifacts/{$artifact->id}/content")
        ->assertOk()
        ->assertStreamed();

    expect($response->baseResponse->isRedirection())->toBeFalse()
        ->and($response->streamedContent())->toBe($content);
});

test('expired org auth artifacts are refused', function (): void {
    $artifact = storedArtifact('<html><body>expired org artifact</body></html>', ArtifactVisibility::OrgAuth, now()->subMinute());
    $artifact->teams()->sync([Team::default()->id]);

    $this->actingAs(User::factory()->create())
        ->get("https://artifacts.capstan.test/artifacts/{$artifact->id}/content")
        ->assertNotFound()
        ->assertDontSee('expired org artifact');
});

test('content route refuses app host and viewer uses opaque-origin sandbox iframe', function (): void {
    $artifact = storedArtifact('<html><body>isolated</body></html>', ArtifactVisibility::SignedUrl, now()->addHour());
    $shareUrl = app(ArtifactRenderOrigin::class)->signedViewerUrl($artifact);

    $this->get("https://app.capstan.test/artifacts/{$artifact->id}/content")
        ->assertNotFound();

    $response = $this->get($shareUrl)
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertSee('sandbox="allow-scripts"', false)
        ->assertDontSee('allow-same-origin', false);

    expect($response->content())->toContain('https://artifacts.capstan.test/artifacts/'.$artifact->id.'/content');
});

test('robots txt disallows artifact paths', function (): void {
    $this->get('/robots.txt')
        ->assertOk()
        ->assertSee('Disallow: /artifacts/');
});

test('artifact serving does not expose storage urls or call temporary disk urls', function (): void {
    $artifact = storedArtifact('<html><body>streamed not redirected</body></html>', ArtifactVisibility::SignedUrl, now()->addHour());

    $response = $this->get(app(ArtifactRenderOrigin::class)->signedContentUrl($artifact))
        ->assertOk()
        ->assertStreamed();

    expect($response->baseResponse->isRedirection())->toBeFalse()
        ->and($response->streamedContent())->toContain('streamed not redirected');

    $source = collect([
        file_get_contents(app_path('Http/Controllers/ArtifactShareController.php')),
        file_get_contents(app_path('Models/Artifact.php')),
    ])->implode("\n");

    expect($source)->not->toContain('temporaryUrl(')->not->toContain('->url(');
});
