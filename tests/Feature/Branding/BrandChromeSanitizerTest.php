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
use Masterminds\HTML5\Parser\DOMTreeBuilder;
use Masterminds\HTML5\Parser\Scanner;
use Masterminds\HTML5\Parser\Tokenizer;

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

/** @return array<string, array{string}> */
function pr5MalformedTreeCases(): array
{
    return [
        'unmatched end tag' => ['</span>'],
        'misnested inline element' => ['<div><span>x</div>'],
        'unclosed nested element' => ['<section><span>x</span>'],
        'implied paragraph end tag' => ['<p>one<div>two</div></p>'],
        'implied table-cell end tag' => ['<table><tbody><tr><td>one<td>two</td></tr></tbody></table>'],
        'balanced source requiring tree-builder recovery' => ['<thead>x<table></table></thead>'],
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

test('tree builder errors are load-bearing when recovered input and canonical trees match', function (): void {
    $html = '<thead>x<table></table></thead>';
    $builder = new DOMTreeBuilder(true, ['disable_html_ns' => true]);
    (new Tokenizer(new Scanner($html, 'UTF-8'), $builder, Tokenizer::CONFORMANT_HTML))->parse();

    expect($builder->getErrors())->not->toBe([])
        ->and(resolve(BrandChromeSanitizer::class)->sanitize('<thead>x</thead><table></table>', []))
        ->toBe('<thead>x</thead><table></table>')
        ->and(fn (): string => resolve(BrandChromeSanitizer::class)->sanitize($html, []))
        ->toThrow(InvalidArgumentException::class, 'is not valid HTML5 chrome');
});

test('structural backstop is load-bearing when an unclosed tree has no builder errors', function (): void {
    $html = '<section><span>x</span>';
    $builder = new DOMTreeBuilder(true, ['disable_html_ns' => true]);
    (new Tokenizer(new Scanner($html, 'UTF-8'), $builder, Tokenizer::CONFORMANT_HTML))->parse();

    expect($builder->getErrors())->toBe([])
        ->and(fn (): string => resolve(BrandChromeSanitizer::class)->sanitize($html, []))
        ->toThrow(InvalidArgumentException::class, 'is not valid HTML5 chrome');
});

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

test('malformed tree write refusal names invalid HTML and leaves every persisted surface unchanged', function (string $html, string $field): void {
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
})->with(pr5MalformedTreeCases())->with(['header_html', 'footer_html']);
