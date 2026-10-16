<?php

use App\Models\BrandAsset;
use App\Models\BrandKit;
use App\Models\BrandVersion;
use App\Support\BrandChromeSanitizer;
use App\Support\BrandKitService;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Masterminds\HTML5;

const PR5_HTML_URL_ATTRIBUTES = [
    'action', 'archive', 'attributionsrc', 'background', 'cite', 'classid', 'codebase',
    'content', 'data', 'formaction', 'href', 'icon', 'imagesrcset', 'itemid', 'longdesc',
    'manifest', 'ping', 'poster', 'profile', 'src', 'srcdoc', 'srcset', 'style', 'usemap',
    'xlink:href',
];

const PR5_EXCLUDED_ELEMENTS = [
    'object', 'embed', 'iframe', 'video', 'audio', 'source', 'picture', 'link', 'meta',
    'base', 'form', 'svg', 'math', 'style', 'script', 'custom-element',
];

/** @return list<string> */
function pr5HtmlNameVariants(string $name): array
{
    $mixed = '';
    foreach (str_split($name) as $index => $character) {
        $mixed .= ctype_alpha($character) && $index % 2 === 1 ? strtoupper($character) : strtolower($character);
    }

    return array_values(array_unique([$name, strtoupper($name), ucfirst($name), $mixed]));
}

/** @return array<string, array{string, string}> */
function pr5UrlAttributeCases(): array
{
    $cases = [];
    foreach (PR5_HTML_URL_ATTRIBUTES as $attribute) {
        foreach (pr5HtmlNameVariants($attribute) as $variant) {
            $cases[$attribute.' as '.$variant] = [$variant, $attribute === 'style' ? 'background:url(https://outside.test/a)' : 'https://outside.test/a'];
        }
    }

    return $cases;
}

/** @return array<string, array{string}> */
function pr5ExcludedElementCases(): array
{
    $cases = [];
    foreach (PR5_EXCLUDED_ELEMENTS as $element) {
        foreach (pr5HtmlNameVariants($element) as $variant) {
            $cases[$element.' as '.$variant] = [$variant];
        }
    }

    return $cases;
}

/** @return array<string, array{string}> */
function pr5WriteRefusalCases(): array
{
    $cases = [];
    foreach (PR5_HTML_URL_ATTRIBUTES as $attribute) {
        $variant = pr5HtmlNameVariants($attribute)[3];
        $value = $attribute === 'style' ? 'background:url(https://outside.test/a)' : 'https://outside.test/a';
        $cases['URL attribute '.$variant] = ['<span '.$variant.'="'.$value.'">x</span>'];
    }
    foreach (PR5_EXCLUDED_ELEMENTS as $element) {
        $cases['element '.$element] = ['<'.$element.'>x</'.$element.'>'];
    }

    return $cases;
}

/** @return array<string, array{string, string}> */
function pr5ChromeRepairCases(): array
{
    return [
        'non-void self close' => ['<div/>x</div>', '<div>x</div>'],
        'misnested element' => ['<div><span>x</div>', '<div><span>x</span></div>'],
        'unmatched end tag' => ['</span>', ''],
        'unclosed nested element' => ['<section><span>x</span>', '<section><span>x</span></section>'],
        'implied paragraph end tag' => ['<p>one<div>two</div></p>', '<p>one</p><div>two</div>'],
        'implied table-cell end tag' => [
            '<table><tbody><tr><td>one<td>two</td></tr></tbody></table>',
            '<table><tbody><tr><td>one</td><td>two</td></tr></tbody></table>',
        ],
        'balanced builder recovery' => ['<thead>x<table></table></thead>', '<thead>x</thead><table></table>'],
        'unclosed quoted attribute' => ['<b title="unclosed>x</b>', '<b title="unclosed&gt;x&lt;/b&gt;"></b>'],
    ];
}

/** @return array<string, array{string}> */
function pr5ChromeContentLossCases(): array
{
    return [
        'dropped null character reference text' => ['<span>before&#0;after</span>'],
        'dropped surrogate character reference text' => ['<div>before&#xD800;after</div>'],
    ];
}

/** @return array<string, array{array<string, int>, array<string, int>, bool}> */
function pr5StartTagPreservationCases(): array
{
    return [
        'dropped sole tag' => [['span' => 1], [], false],
        'dropped repeated tag' => [['section' => 1, 'span' => 2], ['section' => 1, 'span' => 1], false],
        'repair-added tag' => [['span' => 1], ['div' => 1, 'span' => 1], true],
    ];
}

function pr5ChromeValidationException(Closure $callback): ValidationException
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception;
    }

    throw new LogicException('Expected malformed brand chrome to be refused.');
}

beforeEach(function (): void {
    config([
        'app.url' => 'https://app.layouts.test',
        'capstan.artifacts.render_origin' => 'https://render.layouts.test',
    ]);
    Storage::fake();
});

test('chrome sanitizer locks the exact element and attribute allowlists', function (): void {
    expect(BrandChromeSanitizer::ALLOWED_ELEMENTS)->toBe([
        'a', 'abbr', 'address', 'article', 'aside', 'b', 'bdi', 'bdo', 'blockquote', 'br',
        'caption', 'cite', 'code', 'col', 'colgroup', 'dd', 'del', 'details', 'dfn', 'div',
        'dl', 'dt', 'em', 'figcaption', 'figure', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5',
        'h6', 'header', 'hr', 'i', 'img', 'ins', 'kbd', 'li', 'main', 'mark', 'nav', 'ol',
        'p', 'pre', 'q', 'rp', 'rt', 'ruby', 's', 'samp', 'section', 'small', 'span',
        'strong', 'sub', 'summary', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead',
        'time', 'tr', 'u', 'ul', 'var', 'wbr',
    ])->and(BrandChromeSanitizer::ALLOWED_ATTRIBUTES)->toBe([
        'class', 'id', 'role', 'aria-*', 'alt', 'title', 'width', 'height', 'lang', 'dir',
        'style', 'a[href]', 'img[src]',
    ]);
});

test('every frozen URL-bearing attribute and case variant is refused outside the two allowed pairs', function (string $attribute, string $value): void {
    $html = '<span '.$attribute.'="'.$value.'">x</span>';

    expect(fn (): string => resolve(BrandChromeSanitizer::class)->sanitize($html, []))
        ->toThrow(InvalidArgumentException::class);
})->with(pr5UrlAttributeCases());

test('excluded and custom element names are refused after HTML case folding', function (string $element): void {
    expect(fn (): string => resolve(BrandChromeSanitizer::class)->sanitize('<'.$element.'>x</'.$element.'>', []))
        ->toThrow(InvalidArgumentException::class);
})->with(pr5ExcludedElementCases());

test('unknown event data and namespace attributes are refused', function (string $attribute): void {
    expect(fn (): string => resolve(BrandChromeSanitizer::class)->sanitize('<span '.$attribute.'="proof">x</span>', []))
        ->toThrow(InvalidArgumentException::class);
})->with(['onclick', 'data-proof', 'xmlns', 'xmlns:x', 'unknown']);

test('safe structural presentation CSS link and image input becomes idempotent XML compatible chrome', function (): void {
    $assetId = '11111111-1111-4111-8111-111111111111';
    $input = '<SECTION TITLE="Proof" DIR="ltr" LANG="en" STYLE="color:var(--capstan-color-text)" ROLE="banner" ID="top" CLASS="hero" ARIA-LABEL="Header"><A HREF="HTTPS://example.test/path">Link</A><IMG WIDTH="20" TITLE="Logo" SRC="https://render.layouts.test/brand-assets/'.$assetId.'" HEIGHT="10" ALT="Brand"><BR><B>Tail</B></SECTION>';
    $expected = '<section aria-label="Header" class="hero" dir="ltr" id="top" lang="en" role="banner" style="color:var(--capstan-color-text)" title="Proof"><a href="https://example.test/path">Link</a><img alt="Brand" height="10" src="/brand-assets/'.$assetId.'" title="Logo" width="20" /><br /><b>Tail</b></section>';
    $sanitizer = resolve(BrandChromeSanitizer::class);

    expect($sanitizer->sanitize($input, [$assetId]))->toBe($expected)
        ->and($sanitizer->sanitize($expected, [$assetId]))->toBe($expected);

    $xml = new DOMDocument;
    expect($xml->loadXML('<root>'.$expected.'</root>', LIBXML_NONET))->toBeTrue();
    $html5 = new HTML5(['disable_html_ns' => true]);
    expect($html5->saveHTML($html5->loadHTMLFragment($expected)))->not->toBe('');
});

test('well formed explicit trees and void elements remain accepted', function (string $html, string $expected): void {
    expect(resolve(BrandChromeSanitizer::class)->sanitize($html, []))->toBe($expected);
})->with([
    'well-formed nesting' => [
        '<section><div><span>nested</span></div></section>',
        '<section><div><span>nested</span></div></section>',
    ],
    'void br and img' => [
        '<div>before<br><img src="data:image/png;base64,iVBORw0KGgo=" alt="Logo">after</div>',
        '<div>before<br /><img alt="Logo" src="data:image/png;base64,iVBORw0KGgo=" />after</div>',
    ],
    'explicit optional end tags' => [
        '<ul><li>one</li><li>two</li></ul><p>done</p>',
        '<ul><li>one</li><li>two</li></ul><p>done</p>',
    ],
    'no optional-end-tag elements' => [
        '<section><strong>one</strong><span>two</span></section>',
        '<section><strong>one</strong><span>two</span></section>',
    ],
]);

test('allowlisted malformed chrome is repaired to canonical bytes', function (string $html, string $canonical): void {
    $sanitizer = resolve(BrandChromeSanitizer::class);

    expect($sanitizer->sanitize($html, []))->toBe($canonical)
        ->and($sanitizer->sanitize($canonical, []))->toBe($canonical);
})->with(pr5ChromeRepairCases());

test('text observation refuses a repair that drops non-whitespace tokenizer text', function (): void {
    expect(fn (): string => resolve(BrandChromeSanitizer::class)->sanitize('<span>before&#0;after</span>', []))
        ->toThrow(InvalidArgumentException::class, 'is not valid HTML5 chrome');
});

test('start-tag observation requires every source count while permitting repair additions', function (array $source, array $canonical, bool $survives): void {
    $sanitizer = resolve(BrandChromeSanitizer::class);
    $startTagsSurvive = new ReflectionMethod($sanitizer, 'startTagsSurvive');

    expect($startTagsSurvive->invoke($sanitizer, $source, $canonical))->toBe($survives);
})->with(pr5StartTagPreservationCases());

test('https links document fragments and data images are the only public URL values', function (string $html, bool $accepted): void {
    $sanitize = fn (): string => resolve(BrandChromeSanitizer::class)->sanitize($html, []);
    if ($accepted) {
        expect($sanitize())->toBeString();
    } else {
        expect($sanitize)->toThrow(InvalidArgumentException::class);
    }
})->with([
    'HTTPS navigation' => ['<a HREF="HTTPS://example.test/path?q=1#part">Link</a>', true],
    'document fragment' => ['<a href="#local-part">Link</a>', true],
    'data image' => ['<img SRC="DATA:IMAGE/PNG;BASE64,iVBORw0KGgo=" ALT="Brand">', true],
    'HTTP navigation' => ['<a href="http://example.test/path">Link</a>', false],
    'relative navigation' => ['<a href="/path">Link</a>', false],
    'empty fragment' => ['<a href="#">Link</a>', false],
    'external image' => ['<img src="https://example.test/a.png">', false],
    'non-image data' => ['<img src="data:text/plain,proof">', false],
    'invalid base64 image' => ['<img src="data:image/png;base64,%%%">', false],
]);

test('write-time refusal leaves all draft version asset and storage state unchanged for header and footer', function (string $html, string $field): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $service = resolve(BrandKitService::class);

    expect(fn () => $service->updateDraft($owner, [$field => $html]))->toThrow(ValidationException::class)
        ->and(BrandKit::query()->count())->toBe(0);

    $service->updateDraft($owner, ['header_html' => '<b>applied</b>']);
    $service->applyDraft($owner);
    $service->updateDraft($owner, ['footer_html' => '<i>pending</i>']);
    $before = $service->read();
    $files = Storage::disk()->allFiles();

    expect(fn () => $service->updateDraft($owner, [$field => $html]))->toThrow(ValidationException::class)
        ->and($service->read())->toBe($before)
        ->and(BrandVersion::query()->count())->toBe(1)
        ->and(BrandAsset::query()->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBe($files);
})->with(pr5WriteRefusalCases())->with(['header_html', 'footer_html']);

test('repaired chrome writes canonical fields and reports normalization', function (string $html, string $canonical, string $field): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $service = resolve(BrandKitService::class);
    $service->updateDraft($owner, [
        'header_html' => '<strong>before header</strong>',
        'footer_html' => '<i>before footer</i>',
    ]);

    $result = $service->updateDraft($owner, [$field => $html]);
    $stored = $service->read()['draft'];

    expect($result)->toHaveKeys(['header_html', 'footer_html', 'normalized'])
        ->and($result[$field])->toBe($canonical)
        ->and($result['normalized'])->toBeTrue()
        ->and($stored[$field])->toBe($canonical)
        ->and($stored)->not->toHaveKey('normalized');
})->with(pr5ChromeRepairCases())->with(['header_html', 'footer_html']);

test('canonical chrome writes return both stored fields without a normalization signal', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $result = resolve(BrandKitService::class)->updateDraft($owner, [
        'header_html' => '<div><span>header</span></div>',
        'footer_html' => '<p>footer</p>',
    ]);

    expect($result['header_html'])->toBe('<div><span>header</span></div>')
        ->and($result['footer_html'])->toBe('<p>footer</p>')
        ->and($result['normalized'])->toBeFalse();
});

test('content-loss refusal names invalid HTML and leaves every persisted surface unchanged', function (string $html, string $field): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $service = resolve(BrandKitService::class);
    $service->updateDraft($owner, ['header_html' => '<b>applied</b>']);
    $service->applyDraft($owner);
    $service->updateDraft($owner, ['footer_html' => '<i>pending</i>']);

    $before = $service->read();
    $counts = [BrandKit::query()->count(), BrandVersion::query()->count(), BrandAsset::query()->count()];
    $files = Storage::disk()->allFiles();
    $exception = pr5ChromeValidationException(fn () => $service->updateDraft($owner, [$field => $html]));

    expect($exception->errors())->toHaveKey($field)
        ->and($exception->errors()[$field])->toBe(['is not valid HTML5 chrome'])
        ->and($service->read())->toBe($before)
        ->and([BrandKit::query()->count(), BrandVersion::query()->count(), BrandAsset::query()->count()])->toBe($counts)
        ->and(Storage::disk()->allFiles())->toBe($files);
})->with(pr5ChromeContentLossCases())->with(['header_html', 'footer_html']);
