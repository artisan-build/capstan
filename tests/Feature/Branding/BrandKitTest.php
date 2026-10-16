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
    return (string) file_get_contents(base_path('tests/Fixtures/Brand/instrument-sans-400-latin-ext.ttf'));
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
        'font/ttf' => brandTtf(),
    ];
}

function brandReplaceUint32(string $bytes, int $offset, int $value): string
{
    return substr_replace($bytes, pack('N', $value), $offset, 4);
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
        foreach ([['logo_light', (string) Str::uuid()], ['heading_font', $wrongKind->id]] as [$slot, $assetId]) {
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
    $font = $service->uploadAsset($owner, BrandAssetKind::Font, brandTtf());

    $service->selectAsset($owner, 'logo_light', $logo->id);
    $service->selectAsset($admin, 'logo_dark', $logo->id);
    $service->selectAsset($owner, 'mark', $mark->id);
    $service->selectAsset($admin, 'heading_font', $font->id);
    $draft = $service->selectAsset($owner, 'body_font', $font->id);
    expect($draft['assets'])->toBe(['logo_light' => $logo->id, 'logo_dark' => $logo->id, 'mark' => $mark->id])
        ->and($draft['fonts']['heading'])->toBe(['type' => 'asset', 'value' => $font->id])
        ->and($draft['fonts']['body'])->toBe(['type' => 'asset', 'value' => $font->id]);

    $service->selectAsset($admin, 'logo_light', null);
    $service->selectAsset($owner, 'logo_dark', null);
    $service->selectAsset($admin, 'mark', null);
    $service->selectAsset($owner, 'heading_font', null);
    $cleared = $service->selectAsset($admin, 'body_font', null);
    expect($cleared['assets'])->toBe(['logo_light' => null, 'logo_dark' => null, 'mark' => null])
        ->and($cleared['fonts']['heading'])->toBe(['type' => 'stack', 'value' => 'system-sans'])
        ->and($cleared['fonts']['body'])->toBe(['type' => 'stack', 'value' => 'system-sans']);

    $beforeState = $service->read();
    $beforeFiles = Storage::disk()->allFiles();
    $failures = [
        fn () => $service->selectAsset($owner, 'logo_light', (string) Str::uuid()),
        fn () => $service->selectAsset($owner, 'unsupported_slot', $logo->id),
        fn () => $service->selectAsset($owner, 'logo_light', $font->id),
        fn () => $service->selectAsset($owner, 'heading_font', $logo->id),
        fn () => $service->selectAsset($owner, 'logo_dark', $mark->id),
    ];
    foreach ($failures as $failure) {
        expect($failure)->toThrow(ValidationException::class);
        expect($service->read())->toBe($beforeState)
            ->and(BrandVersion::query()->count())->toBe(0)
            ->and(BrandAsset::query()->count())->toBe(3)
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

    $font = brandTtf();
    $tableCount = (int) (unpack('ntables', substr($font, 4, 2))['tables'] ?? 0);
    $directoryEnd = 12 + ($tableCount * 16);
    $headerOnlyTtf = substr($font, 0, 12);
    $emptyDirectoryTtf = substr_replace($font, pack('n', 0), 4, 2);
    $truncatedTtf = substr($font, 0, -1);
    $outOfBoundsDirectoryTtf = brandReplaceUint32($font, 20, strlen($font) + 4);
    $zeroLengthTableTtf = brandReplaceUint32($font, 24, 0);
    $corruptPayloadTtf = substr($font, 0, $directoryEnd).str_repeat('A', strlen($font) - $directoryEnd);
    expect(substr($corruptPayloadTtf, 0, $directoryEnd))->toBe(substr($font, 0, $directoryEnd))
        ->and(substr($corruptPayloadTtf, $directoryEnd))->not->toBe(substr($font, $directoryEnd));
    $invalid = [
        ...array_map(fn (string $bytes): array => [BrandAssetKind::Logo, $bytes], array_values($truncatedImages)),
        [BrandAssetKind::Logo, brandTtf()],
        [BrandAssetKind::Font, brandPng()],
        [BrandAssetKind::Font, $headerOnlyTtf],
        [BrandAssetKind::Font, $emptyDirectoryTtf],
        [BrandAssetKind::Font, $truncatedTtf],
        [BrandAssetKind::Font, $outOfBoundsDirectoryTtf],
        [BrandAssetKind::Font, $zeroLengthTableTtf],
        [BrandAssetKind::Font, $corruptPayloadTtf],
        [BrandAssetKind::Font, 'wOF2'.str_repeat("\x00", 44)],
        [BrandAssetKind::Font, 'wOFF'.str_repeat("\x00", 40)],
        [BrandAssetKind::Font, 'OTTO'.str_repeat("\x00", 24)],
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
    $font = $service->uploadAsset($owner, BrandAssetKind::Font, brandTtf());
    $service->selectAsset($owner, 'logo_light', $logo->id);
    $service->selectAsset($owner, 'heading_font', $font->id);
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
            "font-src 'self' ".$sources['font_src'],
            "img-src 'self' data: ".$sources['img_src'],
            "connect-src 'none'",
            "form-action 'none'",
            "base-uri 'none'",
            'frame-ancestors https://app.brand.test',
        );
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
        'font-src '.$sources['font_src'],
        'img-src data: '.$sources['img_src'],
        "connect-src 'none'",
        "form-action 'none'",
        "base-uri 'none'",
        'frame-ancestors https://app.brand.test',
    )->not->toContain("font-src 'self'", "img-src 'self'");
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
