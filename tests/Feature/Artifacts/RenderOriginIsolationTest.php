<?php

use App\Enums\ArtifactVisibility;
use App\Http\Middleware\NoIndexArtifactPaths;
use App\Models\Artifact;
use App\Models\Team;
use App\Support\ArtifactRenderOrigin;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config([
        'app.url' => 'https://app.test',
        'capstan.features.artifacts' => true,
        'capstan.artifacts.render_origin' => 'https://render.test',
    ]);
    Feature::flushCache();
    Storage::fake();
});

function isolationArtifact(string $content, ArtifactVisibility $visibility = ArtifactVisibility::SignedUrl): Artifact
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

test('the app is unreachable on the render host', function (): void {
    $this->get('https://render.test/')->assertNotFound();
    $this->get('https://render.test/dashboard')->assertNotFound();
    $this->get('https://render.test/login')->assertNotFound();
    $this->post('https://render.test/register')->assertNotFound();
    $this->get('https://render.test/cli/authorize')->assertNotFound();
    $this->get('https://render.test/settings/profile')->assertNotFound();
    $this->post('https://render.test'.route('default-livewire.update', absolute: false))->assertNotFound();
    $this->get('https://render.test/artifacts')->assertNotFound();
});

test('the health probe responds on both hosts', function (): void {
    $this->get('https://render.test/up')->assertOk();
    $this->get('https://app.test/up')->assertOk();
});

test('signed content on the render host streams with strict headers and zero cookies', function (): void {
    $content = '<html><body>cookieless blob</body></html>';
    $artifact = isolationArtifact($content);

    $response = $this->get(app(ArtifactRenderOrigin::class)->signedContentUrl($artifact))
        ->assertOk()
        ->assertStreamed()
        ->assertHeader('Content-Type', 'text/html; charset=utf-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertHeaderMissing('Set-Cookie');

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("default-src 'none'")
        ->toContain('frame-ancestors https://app.test')
        ->and($response->headers->getCookies())->toBe([])
        ->and($response->streamedContent())->toBe($content);
});

test('render host responses never set cookies even on refusals', function (): void {
    $artifact = isolationArtifact('<html><body>refused</body></html>', ArtifactVisibility::OrgAuth);

    $refused = $this->get("https://render.test/artifacts/{$artifact->id}/content")->assertForbidden();
    $blocked = $this->get('https://render.test/dashboard')->assertNotFound();

    expect($refused->headers->getCookies())->toBe([])
        ->and($refused->headers->has('Set-Cookie'))->toBeFalse()
        ->and($blocked->headers->getCookies())->toBe([])
        ->and($blocked->headers->has('Set-Cookie'))->toBeFalse();
});

test('org auth content on the render host is signature-only with no session fallback', function (): void {
    $artifact = isolationArtifact('<html><body>org gated</body></html>', ArtifactVisibility::OrgAuth);
    $artifact->teams()->sync([Team::default()->id]);

    // A browser presenting its app-origin session cookie gets nothing: the
    // render stack has no EncryptCookies/StartSession, so user() stays null.
    $this->withCookie(config('session.cookie'), 'smuggled-session-id')
        ->get("https://render.test/artifacts/{$artifact->id}/content")
        ->assertForbidden();

    $this->get(app(ArtifactRenderOrigin::class)->signedContentUrl($artifact))->assertOk();
});

test('the content route stack is sessionless and cookieless by construction', function (): void {
    $route = Route::getRoutes()->getByName('artifacts.content');
    $middleware = app(Router::class)->gatherRouteMiddleware($route);

    expect($middleware)
        ->toContain(SubstituteBindings::class)
        ->toContain(NoIndexArtifactPaths::class)
        ->not->toContain(StartSession::class)
        ->not->toContain(EncryptCookies::class)
        ->not->toContain(ValidateCsrfToken::class);
});

test('the app origin stays fully intact', function (): void {
    $artifact = isolationArtifact('<html><body>viewer</body></html>');

    $this->get('https://app.test/')->assertOk();

    $this->get(app(ArtifactRenderOrigin::class)->signedViewerUrl($artifact))
        ->assertOk()
        ->assertSee('sandbox="allow-scripts"', false);

    $this->get("https://app.test/artifacts/{$artifact->id}/content")->assertNotFound();
});

test('app host responses still set the session cookie', function (): void {
    $response = $this->get('https://app.test/')->assertOk();
    $cookieNames = array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());

    expect($cookieNames)->toContain(config('session.cookie'));
});
