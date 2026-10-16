<?php

use App\Enums\ArtifactVisibility;
use App\Enums\BrandAssetKind;
use App\Enums\PageLayout;
use App\Enums\PageShareKind;
use App\Models\Artifact;
use App\Models\BrandAsset;
use App\Models\BrandKit;
use App\Models\BrandVersion;
use App\Models\PageShare;
use App\Notifications\ExternalPageShareNotification;
use App\Support\ArtifactRenderOrigin;
use App\Support\BrandKitSchema;
use App\Support\BrandKitService;
use App\Support\BrandPageRenderer;
use App\Support\PageComponentStylesheet;
use App\Support\PageExporter;
use App\Support\PagePublisher;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
        'app.url' => 'https://app.layouts.test',
        'capstan.features.artifacts' => true,
        'capstan.artifacts.render_origin' => 'https://render.layouts.test',
    ]);
    Feature::flushCache();
    Storage::fake();
});

test('version layouts have owner public parity valid XHTML preserved bytes and exact lengths', function (PageLayout $layout, string $type): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $service = resolve(BrandKitService::class);
    $proof = Str::random(16);
    $header = '<p data-testid="fixture-header">Header '.$proof.'</p>';
    $footer = '<p data-testid="fixture-footer">Footer '.$proof.'</p>';
    $service->updateDraft($owner, ['header_html' => $header, 'footer_html' => $footer, 'custom_css' => '.fixture-'.$proof.'{color:var(--capstan-color-accent)}']);
    $service->applyDraft($owner);
    $content = '<section data-testid="fixture-body">Body '.$proof.' Ω</section><script>var decoy="&lt;/body&gt;";</script>';
    $html = '<html xmlns="http://www.w3.org/1999/xhtml"><head><title>'.$proof.'</title></head><body>'.$content.'</body></html>';
    $page = resolve(PagePublisher::class)->publish((string) Str::uuid(), (string) $owner->getKey(), $proof, null, $proof, $layout, $html, $type);
    $share = PageShare::query()->create(['page_id' => $page->id, 'created_by_user_id' => $owner->getKey(), 'kind' => PageShareKind::Link, 'expires_at' => now()->addHour()]);
    $origin = resolve(ArtifactRenderOrigin::class);
    $bodies = [];
    foreach ([$origin->signedPageContentUrl($page, $page->latestVersion), $origin->signedPageShareContentUrl($share)] as $url) {
        $response = $this->get($url)->assertOk()->assertHeaderMissing('Set-Cookie')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $body = $response->streamedContent();
        $bodies[] = $body;
        expect($body)->toContain($content, '.capstan-card{', '.fixture-'.$proof)
            ->and($response->headers->get('Content-Type'))->toStartWith($type)
            ->and($response->headers->get('Content-Length'))->toBe((string) strlen($body))
            ->and($response->headers->get('Content-Security-Policy'))->toContain("connect-src 'none'", "form-action 'none'", "base-uri 'none'", "font-src 'none'", 'img-src data:', 'frame-ancestors https://app.layouts.test');
        $doc = new DOMDocument;
        expect($doc->loadXML($body, LIBXML_NONET))->toBeTrue();
        $xpath = new DOMXPath($doc);
        if ($layout === PageLayout::Plain) {
            expect($body)->not->toContain($header, $footer, 'data-testid="page-layout-', 'data-testid="page-header"', 'data-testid="page-footer"');
        } else {
            expect($body)->toContain('class="capstan-page capstan-layout-'.$layout->value.'"', 'data-testid="page-content"', $header, $footer)
                ->and($xpath->query('//*[@data-testid="page-layout-'.$layout->value.'"]//*[@data-testid="fixture-body"]')->length)->toBe(1);
        }
    }
    expect($bodies[0])->toBe($bodies[1]);
    foreach ([
        $this->actingAsVersioned($owner)->get('https://app.layouts.test'.route('pages.show', ['page' => $page], false)),
        $this->get($origin->signedPageShareViewerUrl($share)),
    ] as $viewer) {
        $viewer->assertOk();
        expect($viewer->content())->toContain('sandbox="allow-scripts"')->not->toContain($header, $footer, '.fixture-'.$proof, '--capstan-color-', 'data-capstan-brand');
    }
})->with(PageLayout::cases())->with(['text/html', 'application/xhtml+xml']);

test('applied chrome rebrands the same immutable version while drafts stay invisible to renders and exports', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $service = resolve(BrandKitService::class);
    $proof = Str::random(16);
    $page = resolve(PagePublisher::class)->publish((string) Str::uuid(), (string) $owner->getKey(), $proof, null, $proof, PageLayout::Report, '<html><head></head><body>'.$proof.'</body></html>', 'text/html');
    $version = $page->latestVersion;
    $url = resolve(ArtifactRenderOrigin::class)->signedPageContentUrl($page, $version);
    $baseline = $this->get($url)->assertOk()->streamedContent();
    $baselineExport = resolve(PageExporter::class)->export($version);
    $header = '<b>rebrand-'.$proof.'</b>';
    $service->updateDraft($owner, ['header_html' => $header, 'footer_html' => '<i>'.$proof.'</i>', 'custom_css' => '.proof-'.$proof.'{color:#abc}', 'colours' => ['light' => ['primary' => '#ab1234']]]);
    expect($this->get($url)->streamedContent())->toBe($baseline)
        ->and(resolve(PageExporter::class)->export($version))->toBe($baselineExport);
    $service->applyDraft($owner);
    expect($this->get($url)->assertOk()->streamedContent())->toContain($header, '.proof-'.$proof, '--capstan-color-primary:#ab1234;')
        ->and(resolve(PageExporter::class)->export($version))->toContain($header, '.proof-'.$proof, '--capstan-color-primary:#ab1234;')
        ->and($page->versions()->count())->toBe(1)
        ->and($page->fresh()->latest_version_id)->toBe($version->id);
    $mail = (new ExternalPageShareNotification((string) Str::uuid(), 'Mail fixture', 'https://app.layouts.test/view'))->toMail($owner)->render();
    expect((string) $mail)->not->toContain($header, '.proof-'.$proof, '--capstan-color-primary:#ab1234;');
    $legacy = '<html><body>legacy-'.$proof.'</body></html>';
    [$hash, $key] = Artifact::storeBlob($legacy);
    $artifact = Artifact::factory()->create(['visibility' => ArtifactVisibility::SignedUrl, 'expires_at' => now()->addHour(), 'content_type' => 'text/html', 'size_bytes' => strlen($legacy), 'content_hash' => $hash, 'storage_key' => $key]);
    $response = $this->get(resolve(ArtifactRenderOrigin::class)->signedContentUrl($artifact))->assertOk();
    expect($response->streamedContent())->toBe($legacy)
        ->and($response->headers->get('Content-Security-Policy'))->toContain("connect-src 'none'", "form-action 'none'", "base-uri 'none'", 'img-src data:', "font-src 'none'");
});

test('invalid chrome and CSS leave draft versions assets and private storage unchanged', function (array $changes): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $service = resolve(BrandKitService::class);
    // Also prove rejection rolls back the first installation-row creation.
    expect(fn () => $service->updateDraft($owner, $changes))->toThrow(ValidationException::class)
        ->and(BrandKit::query()->count())->toBe(0);
    $service->updateDraft($owner, ['header_html' => '<b>'.Str::random(12).'</b>']);
    $service->applyDraft($owner);
    $service->updateDraft($owner, ['footer_html' => '<i>'.Str::random(12).'</i>']);
    $before = $service->read();
    $files = Storage::disk()->allFiles();
    expect(fn () => $service->updateDraft($owner, $changes))->toThrow(ValidationException::class)
        ->and($service->read())->toBe($before)
        ->and(BrandVersion::query()->count())->toBe(1)
        ->and(BrandAsset::query()->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBe($files);
})->with([
    'layout' => [['default_layout' => 'unknown']],
    'unknown' => [['chrome' => 'no']],
    'utf8 html' => [['header_html' => "\xff"]],
    'nul html' => [['footer_html' => "a\0b"]],
    'long header' => [['header_html' => str_repeat('a', 32_769)]],
    'long footer' => [['footer_html' => str_repeat('a', 32_769)]],
    'long CSS' => [['custom_css' => str_repeat('a', 65_537)]],
    'utf8 CSS' => [['custom_css' => "\xff"]],
    'nul CSS' => [['custom_css' => "a\0b"]],
    'breakout' => [['custom_css' => '</StYle><script>evil()</script>']],
    'import' => [['custom_css' => '@import "https://outside.test/a.css";']],
    'url' => [['custom_css' => 'p{background:URL (https://outside.test)}']],
    'escaped url' => [['custom_css' => 'p{background:u\\72l(https://outside.test)}']],
    'comment url' => [['custom_css' => 'p{background:u/**/rl(https://outside.test)}']],
    'image set' => [['custom_css' => 'p{background:image-set("https://outside.test/a.png" 1x)}']],
    'image function' => [['custom_css' => 'p{background:image("https://outside.test/a.png")}']],
    'HTML CSS import' => [['header_html' => '<style>@import "https://outside.test/a.css";</style>']],
    'SVG prefixed CSS import header' => [['header_html' => '<s:svg xmlns:s="http://www.w3.org/2000/svg"><s:style>@import &quot;https://outside.test/x.css&quot;;</s:style></s:svg>']],
    'SVG prefixed CSS import footer' => [['footer_html' => '<s:svg xmlns:s="http://www.w3.org/2000/svg"><s:style>@import &quot;https://outside.test/x.css&quot;;</s:style></s:svg>']],
    'HTML CSS url' => [['footer_html' => '<b style="background:url(https://outside.test)">x</b>']],
    'XML declaration' => [['header_html' => '<?xml version="1.0"?>']],
    'unbalanced HTML' => [['footer_html' => '<b>unclosed']],
]);

test('component public classes and their token driven declarations form the exact authoring contract', function (): void {
    $expected = ['capstan-page', 'capstan-layout-report', 'capstan-layout-dashboard', 'capstan-layout-brief', 'capstan-content', 'capstan-header', 'capstan-footer', 'capstan-card', 'capstan-grid', 'capstan-stat', 'capstan-stat-value', 'capstan-stat-label', 'capstan-table', 'capstan-badge', 'capstan-callout', 'capstan-badge-success', 'capstan-callout-success', 'capstan-badge-warning', 'capstan-callout-warning', 'capstan-badge-danger', 'capstan-callout-danger', 'capstan-chart-series-1', 'capstan-chart-series-2', 'capstan-chart-series-3', 'capstan-chart-series-4', 'capstan-chart-series-5'];
    $kit = resolve(PageComponentStylesheet::class);
    expect(array_keys($kit->rules()))->toBe($expected);
    foreach ($kit->rules() as $class => $declarations) {
        expect($kit->serialize())->toContain('.'.$class.'{'.$declarations.'}');
        foreach (explode(';', $declarations) as $declaration) {
            $value = explode(':', $declaration, 2)[1];
            if (! in_array($value, ['grid'], true)) {
                expect($value)->toContain('var(--capstan-');
            }
        }
        expect($declarations)->not->toMatch('/#[0-9a-f]|url\(|https?:|(?:\d)(?:px|rem|em)/i');
    }
});

test('prefixed XHTML retains namespace semantics and literal ampersand CSS on owner and public routes', function (PageLayout $layout, bool $withHead): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $service = resolve(BrandKitService::class);
    $css = '.fixture::after{content:"a & b"}';
    $service->updateDraft($owner, ['header_html' => '<p data-testid="fixture-header">Header</p>', 'footer_html' => '<p data-testid="fixture-footer">Footer</p>', 'custom_css' => $css]);
    $service->applyDraft($owner);
    $html = '<?xml version="1.0"?><h:html xmlns:h="http://www.w3.org/1999/xhtml">'.($withHead ? '<h:head><h:title>Title</h:title></h:head>' : '').'<h:body><h:p>Body</h:p></h:body></h:html>';
    $page = resolve(PagePublisher::class)->publish((string) Str::uuid(), (string) $owner->getKey(), 'XHTML fixture', null, 'XHTML recipe', $layout, $html, 'application/xhtml+xml');
    $share = PageShare::query()->create(['page_id' => $page->id, 'created_by_user_id' => $owner->getKey(), 'kind' => PageShareKind::Link, 'expires_at' => now()->addHour()]);
    $origin = resolve(ArtifactRenderOrigin::class);
    $bodies = [];
    foreach ([$origin->signedPageContentUrl($page, $page->latestVersion), $origin->signedPageShareContentUrl($share)] as $url) {
        $response = $this->get($url)->assertOk();
        $body = $response->streamedContent();
        $bodies[] = $body;
        expect($body)->toStartWith('<?xml version="1.0"?>')->toContain('<h:p>Body</h:p>', 'content:"a &amp; b"')
            ->and($response->headers->get('Content-Length'))->toBe((string) strlen($body));
        $document = new DOMDocument;
        expect($document->loadXML($body, LIBXML_NONET))->toBeTrue();
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('x', 'http://www.w3.org/1999/xhtml');
        expect($xpath->query('/x:html/x:head/x:style[@data-capstan-brand="applied"]')->length)->toBe(1)
            ->and($xpath->query('//x:style')->item(0)->textContent)->toContain($css);
        if ($layout === PageLayout::Plain) {
            expect($xpath->query('//*[@data-testid="page-content" or @data-testid="page-header" or @data-testid="page-footer"]')->length)->toBe(0);
        } else {
            expect($xpath->query('/x:html/x:body/x:div[@data-testid="page-layout-'.$layout->value.'"]/x:main/x:p')->length)->toBe(1)
                ->and($xpath->query('//x:header/x:p[@data-testid="fixture-header"]')->length)->toBe(1)
                ->and($xpath->query('//x:footer/x:p[@data-testid="fixture-footer"]')->length)->toBe(1);
        }
    }
    expect($bodies[0])->toBe($bodies[1]);
})->with(PageLayout::cases())->with([true, false]);

test('unsupported HTML parser states preserve original bytes without a layout shell', function (string $html): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $service = resolve(BrandKitService::class);
    $service->updateDraft($owner, ['header_html' => '<b>parser-state-header</b>', 'footer_html' => '<i>parser-state-footer</i>']);
    $service->applyDraft($owner);
    foreach (PageLayout::cases() as $layout) {
        $renderer = resolve(BrandPageRenderer::class);
        $body = $renderer->renderResponse($html, 'text/html', $layout)['body'];
        expect($body)->toBe($renderer->renderResponse($html, 'text/html', $layout)['body'])
            ->not->toContain('data-testid="page-layout-', 'parser-state-header', 'parser-state-footer');
        expect(preg_replace('/<style data-capstan-brand="applied">.*?<\/style>/s', '', $body))->toBe($html);
    }
})->with([
    'template body decoy' => '<html><head></head><body><template><p>inside</p></body></template><p>after-template</p></body></html>',
    'double escaped script body decoy' => '<html><head></head><body><script><!--<script>one</script></body>two</script><p>after-script</p></body></html>',
    'headless double escaped script' => '<html><body><script><!--<script>one</script></body><head>decoy</head>two</script><p>after-script</p></body></html>',
    'unclosed escaped script fragment' => '<script><!--<script>one',
    'template fragment' => '<template><p>inside</p></body></template><p>after-template</p>',
]);

test('unresolved owned origin hosts refuse exports case insensitively and opaquely', function (string $reference): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $page = resolve(PagePublisher::class)->publish((string) Str::uuid(), (string) $owner->getKey(), 'Owned fixture', null, 'Owned recipe', PageLayout::Report, '<script src="'.$reference.'"></script>', 'text/html');
    expect(resolve(PageExporter::class)->export($page->latestVersion))->toBeNull();
    $this->actingAsVersioned($owner)->get(route('pages.export', $page))->assertNotFound()->assertContent('');
})->with([
    'https://APP.LAYOUTS.TEST/a.js',
    'https://RENDER.LAYOUTS.TEST/a.js',
    '//APP.LAYOUTS.TEST/a.js',
    '//RENDER.LAYOUTS.TEST/a.js',
]);

test('export refuses linked injected chrome resources but leaves plain and page authored resources alone', function (string $chrome, string $field): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $service = resolve(BrandKitService::class);
    $service->updateDraft($owner, [$field => $chrome]);
    $service->applyDraft($owner);
    $publisher = resolve(PagePublisher::class);
    $page = $publisher->publish((string) Str::uuid(), (string) $owner->getKey(), 'Chrome fixture', null, 'Chrome recipe', PageLayout::Report, '<p>Export fixture</p>', 'text/html');
    expect(resolve(PageExporter::class)->export($page->latestVersion))->toBeNull();
    $this->actingAsVersioned($owner)->get(route('pages.export', $page))->assertNotFound()->assertContent('');
    $page = $publisher->update($page->id, (string) $owner->getKey(), (string) Str::uuid(), ['layout' => PageLayout::Plain], '<p>Export fixture</p>'.$chrome, 'text/html');
    expect(resolve(PageExporter::class)->export($page->latestVersion))->toContain($chrome);
})->with([
    '<link rel="stylesheet" href="https://cdn.outside.test/a.css" />',
    '<script src="https://cdn.outside.test/a.js"></script>',
    '<img src="//cdn.outside.test/a.png" />',
    '<iframe src="https://cdn.outside.test/frame"></iframe>',
    '<img src="/external-image.png" />',
    '<svg xmlns:xlink="http://www.w3.org/1999/xlink"><use xlink:href="https://cdn.outside.test/a.svg#icon" /></svg>',
])->with(['header_html', 'footer_html']);

test('embedded and document local chrome references remain exportable', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $service = resolve(BrandKitService::class);
    $chrome = '<a href="#fixture">Local</a><img src="data:image/png;base64,iVBORw0KGgo=" />';
    $service->updateDraft($owner, ['header_html' => $chrome]);
    $service->applyDraft($owner);
    $page = resolve(PagePublisher::class)->publish((string) Str::uuid(), (string) $owner->getKey(), 'Embedded fixture', null, 'Embedded recipe', PageLayout::Report, '<p id="fixture">Export fixture</p>', 'text/html');
    expect(resolve(PageExporter::class)->export($page->latestVersion))->toContain($chrome);
});

test('branded current-version export inlines applied image bytes and refuses unresolved owned requests', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $service = resolve(BrandKitService::class);
    $image = imagecreatetruecolor(2, 2);
    imagesetpixel($image, 0, 0, random_int(0, 0xFFFFFF));
    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();
    imagedestroy($image);
    $asset = $service->uploadAsset($owner, BrandAssetKind::Logo, $bytes);
    $service->selectAsset($owner, 'logo_light', $asset->id);
    $proof = Str::random(12);
    $service->updateDraft($owner, ['header_html' => '<img src="https://render.layouts.test/brand-assets/'.$asset->id.'" alt="'.$proof.'" />', 'footer_html' => '<b>'.$proof.'</b>', 'custom_css' => '.export-'.$proof.'{color:var(--capstan-color-accent)}']);
    $service->applyDraft($owner);
    $publisher = resolve(PagePublisher::class);
    $page = $publisher->publish((string) Str::uuid(), (string) $owner->getKey(), $proof, null, $proof, PageLayout::Plain, '<p>old-'.$proof.'</p>', 'text/html');
    $page = $publisher->update($page->id, (string) $owner->getKey(), (string) Str::uuid(), ['layout' => PageLayout::Dashboard], '<html><head></head><body><p>new-'.$proof.'</p></body></html>', 'text/html');
    $export = $this->actingAsVersioned($owner)->get(route('pages.export', $page))->assertOk()->streamedContent();
    expect($export)->toContain('data-testid="page-layout-dashboard"', 'new-'.$proof, $proof, 'data:image/png;base64,'.base64_encode($bytes), '.capstan-card{', '.export-'.$proof, '--capstan-logo:url(')
        ->not->toContain('old-'.$proof, 'app.layouts.test', 'render.layouts.test', '/brand-assets/', '/build/assets/', '<link', '<script src');
    $this->actingAsVersioned(capstanUser())->get(route('pages.export', $page))->assertNotFound()->assertContent('');
    foreach (['<img src="/brand-assets/'.Str::uuid().'" />', '<link href="/build/assets/a.css" />', '<script src="https://app.layouts.test/a.js"></script>'] as $unresolved) {
        $page = $publisher->update($page->id, (string) $owner->getKey(), (string) Str::uuid(), [], $unresolved, 'text/html');
        expect(resolve(PageExporter::class)->export($page->latestVersion))->toBeNull();
    }
});

test('default layout and fragment fallback are deterministic and non HTML passes unchanged', function (): void {
    $renderer = resolve(BrandPageRenderer::class);
    $defaults = resolve(BrandKitSchema::class)->defaults();
    expect($defaults['default_layout'])->toBe('report')
        ->and($defaults['header_html'])->toBe('')->and($defaults['footer_html'])->toBe('')->and($defaults['custom_css'])->toBe('');
    $proof = '<section data-testid="fixture-fragment">'.Str::random(12).'</section>';
    expect($renderer->renderResponse($proof, 'text/html', null)['body'])->toContain('data-testid="page-layout-report"', $proof);
    foreach (PageLayout::cases() as $layout) {
        expect($renderer->renderResponse($proof, 'text/plain', $layout))->toBe(['body' => $proof, 'allow_same_origin_images' => false]);
    }
    $xhtml = '<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><body>'.$proof.'</body></html>';
    $rendered = $renderer->renderResponse($xhtml, 'application/xhtml+xml', PageLayout::Brief)['body'];
    expect((new DOMDocument)->loadXML($rendered, LIBXML_NONET))->toBeTrue();
    $malformed = '<html><body><script>var decoy="</body>";';
    $first = $renderer->renderResponse($malformed, 'text/html', PageLayout::Report)['body'];
    expect($first)->toBe($renderer->renderResponse($malformed, 'text/html', PageLayout::Report)['body'])
        ->toContain($malformed)->not->toContain('data-testid="page-layout-report"');
});

test('non HTML owner and public render responses preserve the bytes and content length', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $service = resolve(BrandKitService::class);
    $service->updateDraft($owner, ['header_html' => '<b>'.Str::random(12).'</b>', 'custom_css' => 'b{color:#123}']);
    $service->applyDraft($owner);
    $bytes = "not HTML\0".Str::random(20);
    $page = resolve(PagePublisher::class)->publish((string) Str::uuid(), (string) $owner->getKey(), 'Binary fixture', null, 'Fixture recipe', PageLayout::Report, $bytes, 'application/octet-stream');
    $share = PageShare::query()->create(['page_id' => $page->id, 'created_by_user_id' => $owner->getKey(), 'kind' => PageShareKind::Link, 'expires_at' => now()->addHour()]);
    $origin = resolve(ArtifactRenderOrigin::class);
    foreach ([$origin->signedPageContentUrl($page, $page->latestVersion), $origin->signedPageShareContentUrl($share)] as $url) {
        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('Content-Length', (string) strlen($bytes));
        expect($response->streamedContent())->toBe($bytes);
    }
});

test('installation default is captured at publish from applied state and never overrides a version choice', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $brands = resolve(BrandKitService::class);
    $publish = fn (?PageLayout $layout) => resolve(PagePublisher::class)->publish((string) Str::uuid(), (string) $owner->getKey(), 'Default fixture', null, 'Default recipe', $layout, '<p>'.Str::random(12).'</p>', 'text/html');
    expect($publish(null)->latestVersion->layout)->toBe(PageLayout::Report);
    $brands->updateDraft($owner, ['default_layout' => 'dashboard']);
    expect($publish(null)->latestVersion->layout)->toBe(PageLayout::Report);
    $brands->applyDraft($owner);
    $page = $publish(null);
    expect($page->layout)->toBe(PageLayout::Dashboard)->and($page->latestVersion->layout)->toBe(PageLayout::Dashboard);
    $brands->updateDraft($owner, ['default_layout' => 'brief']);
    $brands->applyDraft($owner);
    expect($page->fresh()->latestVersion->layout)->toBe(PageLayout::Dashboard)
        ->and($publish(PageLayout::Plain)->latestVersion->layout)->toBe(PageLayout::Plain);
});

test('layout shell scanning preserves raw text and comments containing body delimiters', function (): void {
    $proof = Str::random(16);
    $content = '<!-- </body> --><script>var decoy="</body>";var proof="'.$proof.'";</script><textarea>&lt;/body&gt;</textarea><p>'.$proof.'</p>';
    $html = '<html><head></head><body data-value="a>b">'.$content.'</body></html>';
    $rendered = resolve(BrandPageRenderer::class)->renderResponse($html, 'text/html', PageLayout::Report)['body'];
    expect($rendered)->toContain('<main data-testid="page-content" class="capstan-content">'.$content.'</main></div></body>');
});

test('pre chrome snapshots gain read defaults without rewriting the stored applied version', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $old = resolve(BrandKitSchema::class)->defaults();
    foreach (['default_layout', 'header_html', 'footer_html', 'custom_css'] as $field) {
        unset($old[$field]);
    }
    $kit = BrandKit::query()->create(['installation_key' => 'installation', 'current_version_number' => 0]);
    $version = $kit->versions()->create(['version_number' => 1, 'configuration' => $old, 'applied_by_user_id' => $owner->getKey()]);
    $kit->forceFill(['current_version_id' => $version->id, 'current_version_number' => 1])->save();
    $current = resolve(BrandKitService::class)->current();
    expect($current['default_layout'])->toBe('report')->and($current['header_html'])->toBe('')
        ->and($version->fresh()->configuration)->toBe($old);
    $draft = resolve(BrandKitService::class)->updateDraft($owner, ['footer_html' => '<b>'.Str::random(12).'</b>']);
    expect($draft['default_layout'])->toBe('report')->and($draft['header_html'])->toBe('');
});
