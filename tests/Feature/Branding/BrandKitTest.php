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

/** @return array<string, string> */
function brandImageSamples(): array
{
    return [
        'image/png' => brandPng(),
        'image/jpeg' => (string) base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDABALDA4MChAODQ4SERATGCgaGBYWGDEjJR0oOjM9PDkzODdASFxOQERXRTc4UG1RV19iZ2hnPk1xeXBkeFxlZ2P/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAABv/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AHP/Z', true),
        'image/webp' => (string) base64_decode('UklGRhoAAABXRUJQVlA4TA4AAAAvAAAAAAcQEf0PRET/Aw==', true),
    ];
}

function brandRecognizableImageTruncation(string $bytes, string $contentType): string
{
    for ($length = 12; $length < strlen($bytes); $length++) {
        $candidate = substr($bytes, 0, $length);
        $size = @getimagesizefromstring($candidate);
        $decoded = function_exists('imagecreatefromstring') ? @imagecreatefromstring($candidate) : false;
        if ($decoded !== false) {
            imagedestroy($decoded);
        }
        if (is_array($size) && $size['mime'] === $contentType && $decoded === false) {
            return $candidate;
        }
    }

    throw new RuntimeException('The positive image fixture has no recognizable truncated prefix.');
}

function brandPage(User $owner, string $html, string $contentType = 'text/html'): Page
{
    return resolve(PagePublisher::class)->publish(
        pageId: (string) Str::uuid(),
        ownerUserId: (string) $owner->getKey(),
        title: 'Brand page '.Str::random(8),
        description: 'Brand page description',
        refreshRecipe: 'Brand page recipe '.Str::random(8),
        layout: PageLayout::Plain,
        content: $html,
        contentType: $contentType,
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

test('select asset authorizes before missing or wrong-kind lookup for every unavailable principal', function (): void {
    $service = resolve(BrandKitService::class);
    $owner = brandOwner();
    $page = brandPage($owner, '<html><head></head><body>select authorization '.Str::random(8).'</body></html>');
    $service->updateDraft($owner, ['display_name' => 'Applied select baseline '.Str::random(8)]);
    $service->applyDraft($owner);
    $wrongKind = $service->uploadAsset($owner, BrandAssetKind::Logo, brandPng());
    $renderUrl = resolve(ArtifactRenderOrigin::class)->signedPageContentUrl($page, $page->latestVersion);
    $beforePage = $this->get($renderUrl)->assertOk()->streamedContent();
    $beforeState = $service->read();
    $beforeFiles = Storage::disk()->allFiles();
    $member = capstanUser(['role' => UserRole::Member->value]);
    $inactiveAdmin = capstanUser(['role' => UserRole::Admin->value, 'status' => 'inactive']);
    $offboardedAdmin = capstanUser(['role' => UserRole::Admin->value]);
    OffboardedSubject::query()->create([
        'subject_type' => SubjectType::UserPrincipal,
        'subject_ref' => 'capstan-user:'.$offboardedAdmin->getKey(),
        'user_id' => (string) $offboardedAdmin->getKey(),
        'offboarded_at' => now(),
    ]);
    $refusals = [];

    foreach ([$member, $inactiveAdmin, $offboardedAdmin] as $denied) {
        foreach ([['logo_light', (string) Str::uuid()], ['mark', $wrongKind->id]] as [$slot, $assetId]) {
            try {
                $service->selectAsset($denied, $slot, $assetId);
                throw new LogicException('An unavailable principal selected a brand asset.');
            } catch (AuthorizationException $exception) {
                $refusals[] = $exception->getMessage();
            }
        }
    }

    expect($refusals)->toHaveCount(6)
        ->and(array_values(array_unique($refusals)))->toBe(['Only an admitted Owner or Admin may change branding.'])
        ->and($service->read())->toBe($beforeState)
        ->and(BrandVersion::query()->count())->toBe(1)
        ->and(BrandAsset::query()->count())->toBe(1)
        ->and(Storage::disk()->allFiles())->toBe($beforeFiles)
        ->and($this->get($renderUrl)->assertOk()->streamedContent())->toBe($beforePage);
});

test('owner and admin select and clear every asset slot while admitted selection errors change nothing', function (): void {
    $service = resolve(BrandKitService::class);
    $owner = brandOwner();
    $admin = capstanUser(['role' => UserRole::Admin->value]);
    $logo = $service->uploadAsset($owner, BrandAssetKind::Logo, brandPng());
    $mark = $service->uploadAsset($admin, BrandAssetKind::Mark, brandPng());
    foreach ([$owner, $admin] as $actor) {
        foreach (['logo_light' => $logo, 'logo_dark' => $logo, 'mark' => $mark] as $slot => $asset) {
            $selected = $service->selectAsset($actor, $slot, $asset->id);
            expect($selected['assets'][$slot])->toBe($asset->id);
            $cleared = $service->selectAsset($actor, $slot, null);
            expect($cleared['assets'][$slot])->toBeNull();
        }
    }

    $beforeState = $service->read();
    $beforeFiles = Storage::disk()->allFiles();
    $failures = [
        fn () => $service->selectAsset($owner, 'logo_light', (string) Str::uuid()),
        fn () => $service->selectAsset($owner, 'unsupported_slot', $logo->id),
        fn () => $service->selectAsset($owner, 'logo_light', $mark->id),
        fn () => $service->selectAsset($owner, 'mark', $logo->id),
        fn () => $service->selectAsset($owner, 'logo_dark', $mark->id),
    ];
    foreach ($failures as $failure) {
        expect($failure)->toThrow(ValidationException::class);
        expect($service->read())->toBe($beforeState)
            ->and(BrandVersion::query()->count())->toBe(0)
            ->and(BrandAsset::query()->count())->toBe(2)
            ->and(Storage::disk()->allFiles())->toBe($beforeFiles);
    }
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
    'asset non uuid' => [['assets' => ['logo_light' => "x\n}"]]],
    'font asset reference' => [['fonts' => ['heading' => ['type' => 'asset', 'value' => '11111111-1111-4111-8111-111111111111']]]],
    'font extra field' => [['fonts' => ['body' => ['asset_id' => '11111111-1111-4111-8111-111111111111']]]],
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

test('stylesheet injection ignores comments raw text and quoted tag delimiters', function (): void {
    $renderer = resolve(BrandPageRenderer::class);
    $proof = 'head-proof-'.Str::random(12);
    $cases = [
        [
            '<!-- <head> comment decoy '.$proof.' --><html><head><title>'.$proof.'</title></head><body>'.$proof.'</body></html>',
            '<head><style data-capstan-brand="applied">',
            '<!-- <head> comment decoy '.$proof.' -->',
        ],
        [
            '<!doctype html><html><head data-x="a>b"><title>'.$proof.'</title></head><body>'.$proof.'</body></html>',
            '<head data-x="a>b"><style data-capstan-brand="applied">',
            '<head data-x="a>b">',
        ],
        [
            '<html data-decoy="<head>"><head><title>'.$proof.'</title></head><body>'.$proof.'</body></html>',
            '<head><style data-capstan-brand="applied">',
            '<html data-decoy="<head>">',
        ],
        [
            '<html><script>window.decoy="<head> '.$proof.'";</script><head><title>'.$proof.'</title></head><body>'.$proof.'</body></html>',
            '<head><style data-capstan-brand="applied">',
            '<script>window.decoy="<head> '.$proof.'";</script>',
        ],
        [
            '<html><style>.decoy::before{content:"<head> '.$proof.'"}</style><head><title>'.$proof.'</title></head><body>'.$proof.'</body></html>',
            '<head><style data-capstan-brand="applied">',
            '<style>.decoy::before{content:"<head> '.$proof.'"}</style>',
        ],
    ];

    foreach ($cases as [$html, $realHeadWithStyle, $preserved]) {
        $rendered = $renderer->render($html, 'text/html');
        expect(substr_count($rendered, 'data-capstan-brand="applied"'))->toBe(1)
            ->and($rendered)->toContain($realHeadWithStyle, $preserved, '<title>'.$proof.'</title>', '<body>'.$proof.'</body>');
    }
});

test('headless documents and fragments receive an effective style without corrupting their opening bytes', function (): void {
    $renderer = resolve(BrandPageRenderer::class);
    $proof = 'headless-proof-'.Str::random(12);
    $document = '<!doctype html><html data-x="a>b"><body>'.$proof.'</body></html>';
    $fragment = '<section data-proof="'.$proof.'">'.$proof.'</section>';

    $renderedDocument = $renderer->render($document, 'text/html');
    $renderedFragment = $renderer->render($fragment, 'text/html');

    expect($renderedDocument)->toStartWith('<!doctype html><style data-capstan-brand="applied">')
        ->toContain('</style><html data-x="a>b"><body>'.$proof.'</body></html>')
        ->and(substr_count($renderedDocument, 'data-capstan-brand="applied"'))->toBe(1)
        ->and($renderedFragment)->toStartWith('<style data-capstan-brand="applied">')
        ->toContain('</style>'.$fragment)
        ->and(substr_count($renderedFragment, 'data-capstan-brand="applied"'))->toBe(1);
});

test('reviewer self-closing script regression never captures the brand style', function (): void {
    $html = '<!doctype html><html><script /><head id=decoy></head></script><head id=real><title>x</title></head><body>y</body></html>';
    $rendered = resolve(BrandPageRenderer::class)->render($html, 'text/html');

    expect($rendered)->toContain('<head id=real><style data-capstan-brand="applied">')
        ->and(substr_count($rendered, 'data-capstan-brand="applied"'))->toBe(1)
        ->and(preg_replace('/<style data-capstan-brand="applied">.*?<\/style>/s', '', $rendered))->toBe($html);
});

test('raw text insertion respects the response media type', function (string $element, string $contentType, string $slash): void {
    $proof = 'raw-proof-'.Str::random(12);
    $decoy = '<head id=decoy>'.$proof.'</head>';
    $html = '<!doctype html><html><'.$element.$slash.'>'.$decoy.'</'.$element.'><head id=real></head><body>'.$proof.'</body></html>';
    $rendered = resolve(BrandPageRenderer::class)->render($html, $contentType);
    $styleStart = '<style data-capstan-brand="applied">';

    if ($contentType === 'application/xhtml+xml' && $slash !== '') {
        // XML closes the preceding element, so this head is no longer raw text.
        expect($rendered)->toContain('<head id=decoy>'.$styleStart);
    } elseif ($contentType === 'text/html' && $element === 'plaintext') {
        expect($rendered)->toStartWith('<!doctype html>'.$styleStart)
            ->toContain($decoy, '<head id=real></head>');
    } else {
        expect($rendered)->toContain('<head id=real>'.$styleStart, $decoy);
    }

    expect(substr_count($rendered, 'data-capstan-brand="applied"'))->toBe(1)
        ->and(preg_replace('/<style data-capstan-brand="applied">.*?<\/style>/s', '', $rendered))->toBe($html);
})->with(['script', 'style', 'textarea', 'title', 'iframe', 'noembed', 'noframes', 'noscript', 'xmp', 'plaintext'])
    ->with(['text/html', 'application/xhtml+xml'])
    ->with(['', ' /']);

test('unclosed HTML raw text uses fallback before the raw text interval', function (string $element): void {
    $html = '<!doctype html><html><'.$element.' /><head id=decoy>unclosed '.Str::random(12).'</head>';
    $rendered = resolve(BrandPageRenderer::class)->render($html, 'text/html');

    expect($rendered)->toStartWith('<!doctype html><style data-capstan-brand="applied">')
        ->and(substr_count($rendered, 'data-capstan-brand="applied"'))->toBe(1)
        ->and(preg_replace('/<style data-capstan-brand="applied">.*?<\/style>/s', '', $rendered))->toBe($html);
})->with(['script', 'style', 'textarea', 'title', 'iframe', 'noembed', 'noframes', 'noscript', 'xmp', 'plaintext']);

test('owner and public responses pass their media type to insertion and measure transformed bytes', function (string $contentType, string $element): void {
    $owner = brandOwner();
    $service = resolve(BrandKitService::class);
    $logo = $service->uploadAsset($owner, BrandAssetKind::Logo, brandPng());
    $service->selectAsset($owner, 'logo_light', $logo->id);
    $service->applyDraft($owner);
    // A later draft must not affect either CSS or the image capability snapshot.
    $service->selectAsset($owner, 'logo_light', null);
    $html = '<!doctype html><html><'.$element.' /><head id=decoy></head></'.$element.'><head id=real></head><body>'.Str::random(12).'</body></html>';
    $page = brandPage($owner, $html, $contentType);
    $share = PageShare::query()->create([
        'page_id' => $page->id,
        'created_by_user_id' => (string) $owner->getKey(),
        'kind' => PageShareKind::Link,
        'expires_at' => now()->addHour(),
    ]);
    $origin = resolve(ArtifactRenderOrigin::class);
    foreach ([$origin->signedPageContentUrl($page, $page->latestVersion), $origin->signedPageShareContentUrl($share)] as $url) {
        $response = $this->get($url)->assertOk();
        expect($response->headers->get('Content-Type'))->toStartWith($contentType);
        $body = $response->streamedContent();
        $prefix = $contentType === 'application/xhtml+xml'
            ? '<head id=decoy>'
            : ($element === 'plaintext' ? '<!doctype html>' : '<head id=real>');
        expect($body)->toContain($prefix.'<style data-capstan-brand="applied">', $logo->id)
            ->and(substr_count($body, 'data-capstan-brand="applied"'))->toBe(1)
            ->and(preg_replace('/<style data-capstan-brand="applied">.*?<\/style>/s', '', $body))->toBe($html)
            ->and($response->headers->get('Content-Length'))->toBe((string) strlen($body))
            ->and($response->headers->get('Content-Security-Policy'))->toContain("font-src 'none'", "img-src 'self' data:");
    }
})->with(['text/html', 'application/xhtml+xml'])->with(['script', 'plaintext']);

test('short images are validation refusals before rows or private files', function (): void {
    $owner = brandOwner();
    $service = resolve(BrandKitService::class);
    $files = Storage::disk()->allFiles();

    foreach ([BrandAssetKind::Logo, BrandAssetKind::Mark] as $kind) {
        for ($length = 0; $length < 12; $length++) {
            foreach ([substr(brandPng(), 0, $length), str_repeat('A', $length)] as $bytes) {
                expect(fn () => $service->uploadAsset($owner, $kind, $bytes))->toThrow(ValidationException::class)
                    ->and(BrandAsset::query()->count())->toBe(0)
                    ->and(BrandKit::query()->count())->toBe(0)
                    ->and(BrandVersion::query()->count())->toBe(0)
                    ->and(Storage::disk()->allFiles())->toBe($files);
            }
        }
    }
});

test('every fixed font choice serializes without network references', function (string $stack): void {
    $owner = brandOwner();
    $service = resolve(BrandKitService::class);
    $choice = ['type' => 'stack', 'value' => $stack];
    $service->updateDraft($owner, ['fonts' => ['heading' => $choice, 'body' => $choice]]);
    $service->applyDraft($owner);

    $family = match ($stack) {
        'system-serif' => 'ui-serif',
        'monospace' => 'ui-monospace',
        default => 'ui-sans-serif',
    };
    expect($service->current()['fonts'])->toBe(['heading' => $choice, 'body' => $choice])
        ->and(resolve(BrandStylesheet::class)->current())->toContain('--capstan-font-heading:'.$family, '--capstan-font-body:'.$family)
        ->not->toContain('@font-face', 'url(', 'https://', 'http://')
        ->and($service->currentAssetIds())->toBe([]);
})->with(BrandKitSchema::FONT_STACKS);

test('current asset serving is limited to supported image media types', function (): void {
    $owner = brandOwner();
    $service = resolve(BrandKitService::class);
    foreach ([[BrandAssetKind::Logo, 'logo_light'], [BrandAssetKind::Mark, 'mark']] as [$kind, $slot]) {
        foreach (brandImageSamples() as $contentType => $bytes) {
            $asset = $service->uploadAsset($owner, $kind, $bytes);
            $service->selectAsset($owner, $slot, $asset->id);
            $service->applyDraft($owner);
            $url = 'https://render.brand.test/brand-assets/'.$asset->id;
            $response = $this->get($url)->assertOk()->assertHeader('Content-Type', $contentType);
            expect($response->streamedContent())->toBe($bytes);

            $asset->forceFill(['content_type' => 'application/octet-stream'])->save();
            $refused = $this->get($url)->assertNotFound()->assertHeaderMissing('Set-Cookie');
            expect($refused->getContent())->toBe('')->and($refused->headers->getCookies())->toBe([]);
        }
    }
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
    $rowCount = BrandAsset::query()->count();
    $files = Storage::disk()->allFiles();

    $pngPrefix = substr(brandPng(), 0, 25);
    $truncatedImages = [
        'image/png' => $pngPrefix,
        'image/jpeg' => brandRecognizableImageTruncation(brandImageSamples()['image/jpeg'], 'image/jpeg'),
        'image/webp' => brandRecognizableImageTruncation(brandImageSamples()['image/webp'], 'image/webp'),
    ];
    foreach ($truncatedImages as $contentType => $bytes) {
        $metadata = @getimagesizefromstring($bytes);
        expect($metadata)->toBeArray()
            ->and($metadata['mime'])->toBe($contentType)
            ->and(@imagecreatefromstring($bytes))->toBeFalse();
    }

    $invalid = [
        ...array_map(fn (string $bytes): array => [BrandAssetKind::Logo, $bytes], array_values($truncatedImages)),
        [BrandAssetKind::Logo, (string) base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true)],
        ['video', brandPng()],
    ];
    foreach ($invalid as [$kind, $bytes]) {
        expect(fn () => $service->uploadAsset($owner, $kind, $bytes))->toThrow(ValidationException::class)
            ->and(BrandAsset::query()->count())->toBe($rowCount)
            ->and(Storage::disk()->allFiles())->toBe($files);
    }
    expect(BrandAsset::query()->count())->toBe($rowCount)
        ->and(Storage::disk()->allFiles())->toBe($files);

    config(['capstan.brand.max_image_bytes' => strlen(brandPng()) - 1]);
    expect(fn () => $service->uploadAsset($owner, BrandAssetKind::Logo, brandPng()))->toThrow(ValidationException::class)
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
    $service->updateDraft($owner, ['colours' => ['light' => ['primary' => $draftColour]], 'tone_notes' => $tone]);
    $service->selectAsset($owner, 'logo_light', $logo->id);

    foreach ([$ownerUrl, $publicUrl] as $url) {
        $before = $this->get($url)->assertOk()->assertStreamed();
        expect($before->streamedContent())->toContain($distinctiveBody, 'data-capstan-brand="applied"')
            ->not->toContain($draftColour, $tone, $logo->id)
            ->and($before->headers->get('Content-Security-Policy'))
            ->toContain("font-src 'none'", 'img-src data:', "connect-src 'none'", "form-action 'none'", "base-uri 'none'")
            ->not->toContain("font-src 'self'", "img-src 'self'");
    }
    $draftAsset = $this->get('https://render.brand.test/brand-assets/'.$logo->id)
        ->assertNotFound()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertHeaderMissing('Set-Cookie');
    expect($draftAsset->getContent())->toBe('')->and($draftAsset->headers->getCookies())->toBe([]);

    $service->applyDraft($owner);
    foreach ([$ownerUrl, $publicUrl] as $url) {
        $imageOnly = $this->get($url)
            ->assertOk()
            ->assertStreamed()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeaderMissing('Set-Cookie');
        $rendered = $imageOnly->streamedContent();
        expect($rendered)->toContain($distinctiveBody, '--capstan-color-primary:'.$draftColour, $logo->id)
            ->not->toContain($tone)
            ->and($imageOnly->headers->get('Content-Length'))->toBe((string) strlen($rendered))
            ->and($imageOnly->headers->get('Content-Security-Policy'))
            ->toContain("font-src 'none'", "img-src 'self' data:", "connect-src 'none'", "form-action 'none'", "base-uri 'none'")
            ->not->toContain("font-src 'self'")
            ->not->toContain('attacker.test');
    }

    $service->selectAsset($owner, 'logo_light', null);
    $service->updateDraft($owner, ['fonts' => [
        'heading' => ['type' => 'stack', 'value' => 'system-serif'],
        'body' => ['type' => 'stack', 'value' => 'monospace'],
    ]]);
    foreach ([$ownerUrl, $publicUrl] as $url) {
        $fontDraft = $this->get($url)->assertOk();
        expect($fontDraft->streamedContent())->not->toContain('--capstan-font-heading:ui-serif')
            ->and($fontDraft->headers->get('Content-Security-Policy'))
            ->toContain("font-src 'none'", "img-src 'self' data:")
            ->not->toContain("font-src 'self'");
    }

    $service->applyDraft($owner);
    foreach ([$ownerUrl, $publicUrl] as $url) {
        $fontOnly = $this->get($url)->assertOk();
        expect($fontOnly->streamedContent())->toContain('--capstan-font-heading:ui-serif', '--capstan-font-body:ui-monospace')
            ->not->toContain($logo->id, '@font-face', 'url(')
            ->and($fontOnly->headers->get('Content-Security-Policy'))
            ->toContain("font-src 'none'", 'img-src data:', "connect-src 'none'", "form-action 'none'", "base-uri 'none'")
            ->not->toContain("img-src 'self'")
            ->not->toContain('attacker.test');
    }

    $service->selectAsset($owner, 'logo_light', $logo->id);
    $service->applyDraft($owner);
    foreach ([$ownerUrl, $publicUrl] as $url) {
        $withBoth = $this->get($url)->assertOk();
        expect($withBoth->streamedContent())->toContain('--capstan-font-heading:ui-serif', $logo->id)
            ->and($withBoth->headers->get('Content-Security-Policy'))
            ->toContain("font-src 'none'", "img-src 'self' data:", "connect-src 'none'", "form-action 'none'", "base-uri 'none'")
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

    expect($service->currentAssetIds())->toBe([$logo->id])
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
    config(['capstan.features.artifacts' => false]);
    Feature::flushCache();
    $featureOffAsset = $this->get('https://render.brand.test/brand-assets/'.$logo->id)
        ->assertNotFound()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertHeaderMissing('Set-Cookie');
    expect($featureOffAsset->getContent())->toBe('')
        ->and($featureOffAsset->headers->getCookies())->toBe([]);
    config(['capstan.features.artifacts' => true]);
    Feature::flushCache();
    $restoredAsset = $this->get('https://render.brand.test/brand-assets/'.$logo->id)
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeaderMissing('Set-Cookie');
    expect($restoredAsset->streamedContent())->toBe(brandPng());
    $this->get('https://render.brand.test/brand-assets/'.$unreferencedLogo->id)
        ->assertNotFound()
        ->assertHeaderMissing('Set-Cookie');

    $appViewer = $this->actingAsVersioned($owner)
        ->get('https://app.brand.test'.route('pages.show', ['page' => $page], false))
        ->assertOk();
    $publicViewer = $this->get($origin->signedPageShareViewerUrl($share))->assertOk();
    foreach ([$appViewer, $publicViewer] as $viewer) {
        foreach ([$draftColour, $tone, $logo->id, 'data-capstan-brand', '--capstan-color-primary'] as $privateBrandValue) {
            expect($viewer->content())->not->toContain($privateBrandValue);
        }
    }
    $this->get('https://app.brand.test/brand-assets/'.$logo->id)->assertNotFound()->assertDontSee(brandPng());
});

test('configured CSP sources survive applied page branding without widening legacy artifacts', function (): void {
    $suffix = mb_strtolower(Str::random(10));
    $sources = [
        'script_src' => 'https://script-'.$suffix.'.allowed.test',
        'style_src' => 'https://style-'.$suffix.'.allowed.test',
        'font_src' => 'https://font-'.$suffix.'.allowed.test',
        'img_src' => 'https://image-'.$suffix.'.allowed.test',
    ];
    config([
        'capstan.artifacts.csp_allowlist.script_src' => [$sources['script_src']],
        'capstan.artifacts.csp_allowlist.style_src' => [$sources['style_src']],
        'capstan.artifacts.csp_allowlist.font_src' => [$sources['font_src']],
        'capstan.artifacts.csp_allowlist.img_src' => [$sources['img_src']],
    ]);
    $owner = brandOwner();
    $service = resolve(BrandKitService::class);
    $page = brandPage($owner, '<html><head></head><body>configured CSP '.Str::random(8).'</body></html>');
    $share = PageShare::query()->create([
        'page_id' => $page->id,
        'created_by_user_id' => (string) $owner->getKey(),
        'kind' => PageShareKind::Link,
        'expires_at' => now()->addHour(),
    ]);
    $logo = $service->uploadAsset($owner, BrandAssetKind::Logo, brandPng());
    $service->selectAsset($owner, 'logo_light', $logo->id);
    $service->applyDraft($owner);
    $origin = resolve(ArtifactRenderOrigin::class);

    foreach ([
        $origin->signedPageContentUrl($page, $page->latestVersion),
        $origin->signedPageShareContentUrl($share),
    ] as $url) {
        $csp = $this->get($url)->assertOk()->headers->get('Content-Security-Policy');
        expect($csp)->toContain(
            "script-src 'unsafe-inline' ".$sources['script_src'],
            "style-src 'unsafe-inline' ".$sources['style_src'],
            "font-src 'none'",
            "img-src 'self' data: ".$sources['img_src'],
            "connect-src 'none'",
            "form-action 'none'",
            "base-uri 'none'",
            'frame-ancestors https://app.brand.test',
        )->not->toContain($sources['font_src'], "font-src 'self'");
    }

    $artifactBody = '<html><body>configured legacy '.Str::random(8).'</body></html>';
    [$artifactHash, $artifactStorageKey] = Artifact::storeBlob($artifactBody);
    $artifact = Artifact::factory()->create([
        'visibility' => ArtifactVisibility::SignedUrl,
        'expires_at' => now()->addHour(),
        'content_type' => 'text/html',
        'size_bytes' => strlen($artifactBody),
        'content_hash' => $artifactHash,
        'storage_key' => $artifactStorageKey,
    ]);
    $legacyCsp = $this->get($origin->signedContentUrl($artifact))->assertOk()->headers->get('Content-Security-Policy');
    expect($legacyCsp)->toContain(
        "script-src 'unsafe-inline' ".$sources['script_src'],
        "style-src 'unsafe-inline' ".$sources['style_src'],
        "font-src 'none'",
        'img-src data: '.$sources['img_src'],
        "connect-src 'none'",
        "form-action 'none'",
        "base-uri 'none'",
        'frame-ancestors https://app.brand.test',
    )->not->toContain($sources['font_src'], "font-src 'self'", "img-src 'self'");
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
