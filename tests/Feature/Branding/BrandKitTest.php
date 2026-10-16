<?php

use App\Enums\ArtifactVisibility;
use App\Enums\BrandAssetKind;
use App\Enums\PageLayout;
use App\Enums\PageShareKind;
use App\Http\Middleware\NoIndexArtifactPaths;
use App\Models\Artifact;
use App\Models\BrandAsset;
use App\Models\BrandKit;
use App\Models\BrandVersion;
use App\Models\Page;
use App\Models\PageShare;
use App\Support\ArtifactRenderOrigin;
use App\Support\BrandKitSchema;
use App\Support\BrandKitService;
use App\Support\BrandPageRenderer;
use App\Support\BrandStylesheet;
use App\Support\PagePublisher;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('b', 32)),
        'app.name' => 'Safe test default',
        'app.url' => 'https://app.brand.test',
        'capstan.features.artifacts' => true,
        'capstan.artifacts.render_origin' => 'https://render.brand.test',
    ]);
    Feature::flushCache();
    Storage::fake();
});

function brandOwner(array $attributes = []): User
{
    return capstanUser(array_replace(['role' => UserRole::Owner->value], $attributes));
}

function brandPng(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
}

function brandTtf(): string
{
    return "\x00\x01\x00\x00\x00\x01\x00\x00\x00\x00\x00\x00".str_repeat("\x00", 16);
}

/** @return array<string, string> */
function brandImageSamples(): array
{
    return [
        'image/png' => brandPng(),
        'image/jpeg' => (string) base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDABALDA4MChAODQ4SERATGCgaGBYWGDEjJR0oOjM9PDkzODdASFxOQERXRTc4UG1RV19iZ2hnPk1xeXBkeFxlZ2P/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAABv/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AHP/Z', true),
        'image/webp' => (string) base64_decode('UklGRhoAAABXRUJQVlA4TA4AAAAvAAAAAAcQEf0PRET/Aw==', true),
    ];
}

/** @return array<string, string> */
function brandFontSamples(): array
{
    return [
        'font/woff' => 'wOFF'.pack('N', 0x00010000).pack('N', 44).pack('n', 1).str_repeat("\x00", 30),
        'font/woff2' => 'wOF2'.pack('N', 0x00010000).pack('N', 48).pack('n', 1).str_repeat("\x00", 34),
        'font/ttf' => brandTtf(),
        'font/otf' => 'OTTO'."\x00\x01\x00\x00\x00\x00\x00\x00".str_repeat("\x00", 16),
    ];
}

function brandPage(User $owner, string $html): Page
{
    return resolve(PagePublisher::class)->publish(
        pageId: (string) Str::uuid(),
        ownerUserId: (string) $owner->getKey(),
        title: 'Brand page '.Str::random(8),
        description: 'Brand page description',
        refreshRecipe: 'Brand page recipe '.Str::random(8),
        layout: PageLayout::Plain,
        content: $html,
        contentType: 'text/html',
    );
}

test('first read returns complete code defaults without durable state', function (): void {
    $state = resolve(BrandKitService::class)->read();

    expect($state['current'])->toBe($state['defaults'])
        ->and($state['draft'])->toBeNull()
        ->and($state['current_version'])->toBeNull()
        ->and($state['defaults'])->toHaveKeys(['display_name', 'colours', 'spacing', 'radius', 'fonts', 'assets', 'tone_notes'])
        ->and($state['defaults']['colours'])->toHaveKeys(['light', 'dark'])
        ->and(array_keys($state['defaults']['colours']['light']))->toBe(BrandKitSchema::COLOUR_TOKENS)
        ->and(BrandKit::query()->count())->toBe(0)
        ->and(BrandVersion::query()->count())->toBe(0);
});

test('drafts normalize apply atomically and leave monotonic immutable versions', function (): void {
    $owner = brandOwner();
    $admin = capstanUser(['role' => UserRole::Admin->value]);
    $service = resolve(BrandKitService::class);
    $firstName = 'First applied '.Str::random(8);
    $secondName = 'Second applied '.Str::random(8);

    $draft = $service->updateDraft($owner, [
        'display_name' => '  '.$firstName.'  ',
        'colours' => ['light' => ['primary' => '#A1B2C3']],
        'spacing' => ['md' => '1.25rem'],
        'radius' => ['pill' => '0'],
        'fonts' => ['heading' => ['type' => 'stack', 'value' => 'system-serif']],
        'tone_notes' => "Direct and calm\nTest-created",
    ]);
    expect($draft['display_name'])->toBe($firstName)
        ->and($draft['colours']['light']['primary'])->toBe('#a1b2c3')
        ->and($service->read()['current']['display_name'])->toBe('Safe test default');

    $first = $service->applyDraft($owner);
    $firstSnapshot = $first->configuration;
    expect($first->version_number)->toBe(1)
        ->and($service->read()['draft'])->toBeNull()
        ->and($service->read()['current'])->toBe($firstSnapshot);

    $service->updateDraft($admin, ['display_name' => $secondName, 'colours' => ['dark' => ['primary' => '#1234']]]);
    expect($service->read()['current']['display_name'])->toBe($firstName)
        ->and($service->read()['draft']['display_name'])->toBe($secondName)
        ->and($first->fresh()?->configuration)->toBe($firstSnapshot);

    $failPointerUpdate = true;
    BrandKit::updating(function (BrandKit $kit) use (&$failPointerUpdate): void {
        if ($failPointerUpdate && $kit->current_version_number === 2) {
            throw new RuntimeException('Test-created pointer update failure');
        }
    });
    expect(fn () => $service->applyDraft($admin))->toThrow(RuntimeException::class);
    $failPointerUpdate = false;
    $rolledBack = BrandKit::query()->firstOrFail();
    expect(BrandVersion::query()->count())->toBe(1)
        ->and($rolledBack->current_version_id)->toBe($first->id)
        ->and($rolledBack->current_version_number)->toBe(1)
        ->and($rolledBack->draft['display_name'])->toBe($secondName);

    $second = $service->applyDraft($admin);
    expect($second->version_number)->toBe(2)
        ->and($service->read()['current']['display_name'])->toBe($secondName)
        ->and($first->fresh()?->configuration)->toBe($firstSnapshot)
        ->and(fn () => $first->forceFill(['version_number' => 99])->save())->toThrow(LogicException::class)
        ->and(fn () => $first->delete())->toThrow(LogicException::class);
});

test('non-admin and unavailable principals are refused before every brand side effect', function (): void {
    $service = resolve(BrandKitService::class);
    $pageOwner = brandOwner();
    $page = brandPage($pageOwner, '<html><head></head><body>authorization page</body></html>');
    $renderUrl = resolve(ArtifactRenderOrigin::class)->signedPageContentUrl($page, $page->latestVersion);
    $before = $this->get($renderUrl)->assertOk()->streamedContent();
    $filesBefore = Storage::disk()->allFiles();
    $member = capstanUser(['role' => UserRole::Member->value]);
    $inactiveAdmin = capstanUser(['role' => UserRole::Admin->value, 'status' => 'inactive']);
    $offboardedOwner = capstanUser(['role' => UserRole::Admin->value]);
    OffboardedSubject::query()->create([
        'subject_type' => SubjectType::UserPrincipal,
        'subject_ref' => 'capstan-user:'.$offboardedOwner->getKey(),
        'user_id' => (string) $offboardedOwner->getKey(),
        'offboarded_at' => now(),
    ]);

    foreach ([$member, $inactiveAdmin, $offboardedOwner] as $denied) {
        expect(fn () => $service->updateDraft($denied, ['display_name' => 'Forbidden '.Str::random(8)]))
            ->toThrow(AuthorizationException::class);
        expect(fn () => $service->uploadAsset($denied, BrandAssetKind::Logo, brandPng()))
            ->toThrow(AuthorizationException::class);
        expect(fn () => $service->applyDraft($denied))->toThrow(AuthorizationException::class);
    }

    expect(BrandKit::query()->count())->toBe(0)
        ->and(BrandVersion::query()->count())->toBe(0)
        ->and(BrandAsset::query()->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBe($filesBefore)
        ->and($this->get($renderUrl)->assertOk()->streamedContent())->toBe($before);
});

test('token grammar rejects unknown names css breakouts and unsafe font choices', function (array $changes): void {
    $service = resolve(BrandKitService::class);

    expect(fn () => $service->updateDraft(brandOwner(), $changes))->toThrow(ValidationException::class)
        ->and(BrandKit::query()->count())->toBe(0)
        ->and(BrandVersion::query()->count())->toBe(0);
})->with([
    'unknown token' => [['colours' => ['light' => ['attacker' => '#fff']]]],
    'declaration breakout' => [['colours' => ['light' => ['primary' => 'red;}</style><script>alert(1)</script>']]]],
    'url breakout' => [['spacing' => ['md' => '1px;background:url(https://attacker.test)']]],
    'negative length' => [['radius' => ['md' => '-1px']]],
    'unitless length' => [['spacing' => ['lg' => '12']]],
    'arbitrary font family' => [['fonts' => ['body' => ['type' => 'stack', 'value' => 'Arial;src:url(evil)']]]],
    'asset non uuid' => [['fonts' => ['heading' => ['type' => 'asset', 'value' => "x\n}"]]]],
]);

test('serialized css uses only fixed declarations and canonical values', function (): void {
    $service = resolve(BrandKitService::class);
    $owner = brandOwner();
    $service->updateDraft($owner, [
        'colours' => ['light' => ['primary' => '#ABC']],
        'spacing' => ['md' => '2.5rem'],
        'fonts' => ['body' => ['type' => 'stack', 'value' => 'monospace']],
        'tone_notes' => '</style><script>toneMustStayOut()</script>',
    ]);
    $service->applyDraft($owner);

    $css = resolve(BrandStylesheet::class)->current();
    expect($css)->toContain('--capstan-color-primary:#abc;', '--capstan-space-md:2.5rem;', '--capstan-font-body:ui-monospace')
        ->not->toContain('toneMustStayOut', '</style>', '<script>', 'https://', 'javascript:', "\n");
});

test('asset uploads sniff bytes enforce limits and leave invalid input side effect free', function (): void {
    $owner = brandOwner();
    $service = resolve(BrandKitService::class);

    foreach (brandImageSamples() as $contentType => $bytes) {
        $asset = $service->uploadAsset($owner, BrandAssetKind::Logo, $bytes);
        expect($asset->content_type)->toBe($contentType)
            ->and($asset->size_bytes)->toBe(strlen($bytes))
            ->and(Storage::disk()->get($asset->storage_key))->toBe($bytes);
    }
    foreach (brandFontSamples() as $contentType => $bytes) {
        $asset = $service->uploadAsset($owner, BrandAssetKind::Font, $bytes);
        expect($asset->content_type)->toBe($contentType)
            ->and($asset->size_bytes)->toBe(strlen($bytes))
            ->and(Storage::disk()->get($asset->storage_key))->toBe($bytes);
    }
    $rowCount = BrandAsset::query()->count();
    $files = Storage::disk()->allFiles();

    $invalid = [
        [BrandAssetKind::Logo, "\x89PNG\r\n\x1a\ntruncated"],
        [BrandAssetKind::Logo, brandTtf()],
        [BrandAssetKind::Font, brandPng()],
        [BrandAssetKind::Logo, (string) base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true)],
        ['video', brandPng()],
    ];
    foreach ($invalid as [$kind, $bytes]) {
        expect(fn () => $service->uploadAsset($owner, $kind, $bytes))->toThrow(ValidationException::class);
    }
    expect(BrandAsset::query()->count())->toBe($rowCount)
        ->and(Storage::disk()->allFiles())->toBe($files);

    config(['capstan.brand.max_image_bytes' => strlen(brandPng()) - 1]);
    expect(fn () => $service->uploadAsset($owner, BrandAssetKind::Logo, brandPng()))->toThrow(ValidationException::class)
        ->and(BrandAsset::query()->count())->toBe($rowCount)
        ->and(Storage::disk()->allFiles())->toBe($files);

    config(['capstan.brand.max_font_bytes' => strlen(brandTtf()) - 1]);
    expect(fn () => $service->uploadAsset($owner, BrandAssetKind::Font, brandTtf()))->toThrow(ValidationException::class)
        ->and(BrandAsset::query()->count())->toBe($rowCount)
        ->and(Storage::disk()->allFiles())->toBe($files);
});

test('page CSP follows applied asset classes while drafts and legacy artifacts stay least privilege', function (): void {
    $owner = brandOwner();
    $service = resolve(BrandKitService::class);
    $distinctiveBody = 'existing-page-'.Str::random(12);
    $draftColour = '#1a2b3c';
    $tone = 'private-tone-'.Str::random(12);
    $page = brandPage($owner, '<!doctype html><html><head><title>Brand</title></head><body>'.$distinctiveBody.'</body></html>');
    $share = PageShare::query()->create([
        'page_id' => $page->id,
        'created_by_user_id' => (string) $owner->getKey(),
        'kind' => PageShareKind::Link,
        'expires_at' => now()->addHour(),
    ]);
    $origin = resolve(ArtifactRenderOrigin::class);
    $ownerUrl = $origin->signedPageContentUrl($page, $page->latestVersion);
    $publicUrl = $origin->signedPageShareContentUrl($share);
    $logo = $service->uploadAsset($owner, BrandAssetKind::Logo, brandPng());
    $unreferencedLogo = $service->uploadAsset($owner, BrandAssetKind::Logo, brandPng());
    $font = $service->uploadAsset($owner, BrandAssetKind::Font, brandTtf());
    $service->updateDraft($owner, ['colours' => ['light' => ['primary' => $draftColour]], 'tone_notes' => $tone]);
    $service->selectAsset($owner, 'logo_light', $logo->id);
    $service->selectAsset($owner, 'heading_font', $font->id);

    foreach ([$ownerUrl, $publicUrl] as $url) {
        $before = $this->get($url)->assertOk()->assertStreamed();
        expect($before->streamedContent())->toContain($distinctiveBody, 'data-capstan-brand="applied"')
            ->not->toContain($draftColour, $tone, $logo->id, $font->id)
            ->and($before->headers->get('Content-Security-Policy'))
            ->toContain("font-src 'none'", 'img-src data:', "connect-src 'none'", "form-action 'none'", "base-uri 'none'")
            ->not->toContain("font-src 'self'", "img-src 'self'");
    }
    $draftAsset = $this->get('https://render.brand.test/brand-assets/'.$logo->id)
        ->assertNotFound()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertHeaderMissing('Set-Cookie');
    expect($draftAsset->getContent())->toBe('')->and($draftAsset->headers->getCookies())->toBe([]);

    $service->selectAsset($owner, 'heading_font', null);
    $service->applyDraft($owner);
    foreach ([$ownerUrl, $publicUrl] as $url) {
        $imageOnly = $this->get($url)
            ->assertOk()
            ->assertStreamed()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeaderMissing('Set-Cookie');
        $rendered = $imageOnly->streamedContent();
        expect($rendered)->toContain($distinctiveBody, '--capstan-color-primary:'.$draftColour, $logo->id)
            ->not->toContain($font->id)
            ->not->toContain($tone)
            ->and($imageOnly->headers->get('Content-Length'))->toBe((string) strlen($rendered))
            ->and($imageOnly->headers->get('Content-Security-Policy'))
            ->toContain("font-src 'none'", "img-src 'self' data:", "connect-src 'none'", "form-action 'none'", "base-uri 'none'")
            ->not->toContain("font-src 'self'")
            ->not->toContain('attacker.test');
    }

    $service->selectAsset($owner, 'heading_font', $font->id);
    $service->selectAsset($owner, 'logo_light', null);
    foreach ([$ownerUrl, $publicUrl] as $url) {
        $fontDraft = $this->get($url)->assertOk();
        expect($fontDraft->streamedContent())->not->toContain($font->id)
            ->and($fontDraft->headers->get('Content-Security-Policy'))
            ->toContain("font-src 'none'", "img-src 'self' data:")
            ->not->toContain("font-src 'self'");
    }

    $service->applyDraft($owner);
    foreach ([$ownerUrl, $publicUrl] as $url) {
        $fontOnly = $this->get($url)->assertOk();
        expect($fontOnly->streamedContent())->toContain($font->id)
            ->not->toContain($logo->id)
            ->and($fontOnly->headers->get('Content-Security-Policy'))
            ->toContain("font-src 'self'", 'img-src data:', "connect-src 'none'", "form-action 'none'", "base-uri 'none'")
            ->not->toContain("img-src 'self'")
            ->not->toContain('attacker.test');
    }

    $service->selectAsset($owner, 'logo_light', $logo->id);
    $service->applyDraft($owner);
    foreach ([$ownerUrl, $publicUrl] as $url) {
        $withBoth = $this->get($url)->assertOk();
        expect($withBoth->streamedContent())->toContain($font->id, $logo->id)
            ->and($withBoth->headers->get('Content-Security-Policy'))
            ->toContain("font-src 'self'", "img-src 'self' data:", "connect-src 'none'", "form-action 'none'", "base-uri 'none'")
            ->not->toContain('attacker.test');
    }

    $artifactBody = '<html><body>legacy artifact '.Str::random(8).'</body></html>';
    [$artifactHash, $artifactStorageKey] = Artifact::storeBlob($artifactBody);
    $artifact = Artifact::factory()->create([
        'visibility' => ArtifactVisibility::SignedUrl,
        'expires_at' => now()->addHour(),
        'content_type' => 'text/html',
        'size_bytes' => strlen($artifactBody),
        'content_hash' => $artifactHash,
        'storage_key' => $artifactStorageKey,
    ]);
    $legacyArtifact = $this->get($origin->signedContentUrl($artifact))->assertOk();
    $legacyCsp = $legacyArtifact->headers->get('Content-Security-Policy');
    expect($legacyCsp)->toContain("font-src 'none'", 'img-src data:', "connect-src 'none'", "form-action 'none'", "base-uri 'none'")
        ->not->toContain("font-src 'self'", "img-src 'self'");

    expect($service->currentAssetIds())->toContain($logo->id, $font->id)
        ->and(BrandAsset::query()->find($logo->id))->toBeInstanceOf(BrandAsset::class)
        ->and(Storage::disk()->exists($logo->storage_key))->toBeTrue();
    $assetResponse = $this->get('https://render.brand.test/brand-assets/'.$logo->id)
        ->assertOk()
        ->assertStreamed()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Content-Length', (string) strlen(brandPng()))
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertHeaderMissing('Set-Cookie');
    expect($assetResponse->streamedContent())->toBe(brandPng())
        ->and($assetResponse->headers->get('Cache-Control'))->toContain('private', 'no-store')
        ->and($assetResponse->headers->getCookies())->toBe([]);
    $this->get('https://render.brand.test/brand-assets/'.$font->id)
        ->assertOk()
        ->assertHeader('Content-Type', 'font/ttf')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeaderMissing('Set-Cookie');
    $this->get('https://render.brand.test/brand-assets/'.$unreferencedLogo->id)
        ->assertNotFound()
        ->assertHeaderMissing('Set-Cookie');

    $appViewer = $this->actingAsVersioned($owner)
        ->get('https://app.brand.test'.route('pages.show', ['page' => $page], false))
        ->assertOk();
    $publicViewer = $this->get($origin->signedPageShareViewerUrl($share))->assertOk();
    foreach ([$appViewer, $publicViewer] as $viewer) {
        foreach ([$draftColour, $tone, $logo->id, $font->id, 'data-capstan-brand', '--capstan-color-primary'] as $privateBrandValue) {
            expect($viewer->content())->not->toContain($privateBrandValue);
        }
    }
    $this->get('https://app.brand.test/brand-assets/'.$logo->id)->assertNotFound()->assertDontSee(brandPng());
});

test('brand asset route is narrowly sessionless and non html bodies are not transformed', function (): void {
    $route = Route::getRoutes()->getByName('brand-assets.show');
    $middleware = resolve(Router::class)->gatherRouteMiddleware($route);
    expect($middleware)->toContain(SubstituteBindings::class, NoIndexArtifactPaths::class)
        ->not->toContain(StartSession::class, EncryptCookies::class, ValidateCsrfToken::class);

    $plain = 'not-html-'.Str::random(12);
    expect(resolve(BrandPageRenderer::class)->render($plain, 'text/plain'))->toBe($plain);

    $missing = $this->get('https://render.brand.test/brand-assets/'.Str::uuid())
        ->assertNotFound()
        ->assertHeaderMissing('Set-Cookie');
    expect($missing->getContent())->toBe('')->and($missing->headers->getCookies())->toBe([]);
});
