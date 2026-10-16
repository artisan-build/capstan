<?php

namespace App\Support;

use Closure;
use DOMDocumentFragment;
use DOMElement;
use DOMNode;
use InvalidArgumentException;
use Masterminds\HTML5\Parser\DOMTreeBuilder;
use Masterminds\HTML5\Parser\EventHandler;
use Masterminds\HTML5\Parser\Scanner;
use Masterminds\HTML5\Parser\Tokenizer;

final readonly class BrandChromeSanitizer
{
    /** @var list<string> */
    public const array ALLOWED_ELEMENTS = [
        'a', 'abbr', 'address', 'article', 'aside', 'b', 'bdi', 'bdo', 'blockquote', 'br',
        'caption', 'cite', 'code', 'col', 'colgroup', 'dd', 'del', 'details', 'dfn', 'div',
        'dl', 'dt', 'em', 'figcaption', 'figure', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5',
        'h6', 'header', 'hr', 'i', 'img', 'ins', 'kbd', 'li', 'main', 'mark', 'nav', 'ol',
        'p', 'pre', 'q', 'rp', 'rt', 'ruby', 's', 'samp', 'section', 'small', 'span',
        'strong', 'sub', 'summary', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead',
        'time', 'tr', 'u', 'ul', 'var', 'wbr',
    ];

    /** @var list<string> */
    public const array ALLOWED_ATTRIBUTES = [
        'class', 'id', 'role', 'aria-*', 'alt', 'title', 'width', 'height', 'lang', 'dir',
        'style', 'a[href]', 'img[src]',
    ];

    /** @var list<string> */
    private const array VOID_ELEMENTS = ['br', 'col', 'hr', 'img', 'wbr'];

    public function __construct(private BrandCssGrammar $css) {}

    /**
     * @param  list<string>  $selectedAssetIds
     */
    public function sanitize(string $html, array $selectedAssetIds): string
    {
        $this->assertXmlText($html);
        if ($html === '') {
            return '';
        }

        $selected = [];
        foreach ($selectedAssetIds as $id) {
            $selected[strtolower($id)] = true;
        }

        try {
            $parsed = $this->parseFragment($html, $selected);
            $canonical = $this->serializeFragment($parsed['fragment']);
            $reparsed = $this->parseFragment($canonical, $selected);

            if ($this->normalizeObservedText($parsed['text']) !== $this->normalizeObservedText($reparsed['text'])
                || ! $this->startTagsSurvive($parsed['start_tags'], $reparsed['start_tags'])) {
                throw new InvalidArgumentException('is not valid HTML5 chrome');
            }

            return $canonical;
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('is not valid HTML5 chrome', previous: $exception);
        }
    }

    /**
     * @param  array<string, true>  $selectedAssetIds
     * @return array{fragment: DOMDocumentFragment, text: string, start_tags: array<string, int>}
     */
    private function parseFragment(string $html, array $selectedAssetIds): array
    {
        $builder = new DOMTreeBuilder(true, ['disable_html_ns' => true]);
        $startTag = fn (string $name, array $attributes): array => $this->normalizeStartTag($name, $attributes, $selectedAssetIds);
        $endTag = function (string $name): void {
            $name = strtolower($name);
            if (! in_array($name, self::ALLOWED_ELEMENTS, true)) {
                throw new InvalidArgumentException('contains an element outside the allowlist');
            }
        };
        $handler = new class($builder, $startTag, $endTag) implements EventHandler
        {
            private string $text = '';

            /** @var array<string, int> */
            private array $startTags = [];

            public function __construct(
                private readonly DOMTreeBuilder $builder,
                private readonly Closure $startTag,
                private readonly Closure $endTag,
            ) {}

            public function doctype($name, $idType = 0, $id = null, $quirks = false): never
            {
                throw new InvalidArgumentException('contains a declaration, comment, or unsupported node');
            }

            /**
             * @param  string  $name
             * @param  array<string, string|null>  $attributes
             */
            public function startTag($name, $attributes = [], $selfClosing = false): int
            {
                $name = strtolower($name);
                $attributes = ($this->startTag)($name, $attributes);
                $this->startTags[$name] = ($this->startTags[$name] ?? 0) + 1;

                return $this->builder->startTag($name, $attributes, $selfClosing);
            }

            /** @param string $name */
            public function endTag($name): void
            {
                $name = strtolower($name);
                ($this->endTag)($name);
                $this->builder->endTag($name);
            }

            /** @param string $cdata */
            public function comment($cdata): never
            {
                throw new InvalidArgumentException('contains a declaration, comment, or unsupported node');
            }

            /** @param string $cdata */
            public function text($cdata): void
            {
                $this->text .= $cdata;
                $this->builder->text($cdata);
            }

            public function eof(): void
            {
                $this->builder->eof();
            }

            /**
             * @param  string  $msg
             * @param  int  $line
             * @param  int  $col
             */
            public function parseError($msg, $line, $col): void
            {
                // HTML5 parse errors are recoverable. Content observations below
                // decide whether the parser's canonical repair is lossless.
            }

            public function cdata($data): never
            {
                throw new InvalidArgumentException('contains a declaration, comment, or unsupported node');
            }

            public function processingInstruction($name, $data = null): never
            {
                throw new InvalidArgumentException('contains a declaration, comment, or unsupported node');
            }

            public function observedText(): string
            {
                return $this->text;
            }

            /** @return array<string, int> */
            public function observedStartTags(): array
            {
                return $this->startTags;
            }
        };

        $parser = new Tokenizer(new Scanner($html, 'UTF-8'), $handler, Tokenizer::CONFORMANT_HTML);
        $parser->parse();

        return [
            'fragment' => $builder->fragment(),
            'text' => $handler->observedText(),
            'start_tags' => $handler->observedStartTags(),
        ];
    }

    private function normalizeObservedText(string $text): string
    {
        return trim((string) preg_replace('/[ \t\n\f\r]+/', ' ', $text), " \t\n\f\r");
    }

    /**
     * @param  array<string, int>  $source
     * @param  array<string, int>  $canonical
     */
    private function startTagsSurvive(array $source, array $canonical): bool
    {
        foreach ($source as $name => $count) {
            if (($canonical[$name] ?? 0) < $count) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string|null>  $attributes
     * @param  array<string, true>  $selectedAssetIds
     * @return array<string, string>
     */
    private function normalizeStartTag(string $name, array $attributes, array $selectedAssetIds): array
    {
        $name = strtolower($name);
        if (! in_array($name, self::ALLOWED_ELEMENTS, true)) {
            throw new InvalidArgumentException('contains an element outside the allowlist');
        }

        $normalized = [];
        foreach ($attributes as $attribute => $value) {
            $attribute = strtolower($attribute);
            $value = (string) $value;
            $this->assertXmlText($value);

            if ($attribute === 'href' && $name === 'a') {
                $value = $this->navigationUrl($value);
            } elseif ($attribute === 'src' && $name === 'img') {
                $value = $this->imageUrl($value, $selectedAssetIds);
            } elseif ($attribute === 'style') {
                $this->css->assertValid($value);
            } elseif (! in_array($attribute, ['class', 'id', 'role', 'alt', 'title', 'width', 'height', 'lang', 'dir'], true)
                && preg_match('/\Aaria-[a-z0-9_-]+\z/D', $attribute) !== 1) {
                throw new InvalidArgumentException('contains an attribute outside the allowlist');
            }

            $normalized[$attribute] = $value;
        }

        ksort($normalized);

        return $normalized;
    }

    private function navigationUrl(string $value): string
    {
        if (str_starts_with($value, '#')) {
            $fragment = substr($value, 1);
            if ($fragment === '' || preg_match('/\A(?:[A-Za-z0-9._~!$&\'()*+,;=:@\/?-]|%[0-9A-Fa-f]{2})+\z/D', $fragment) !== 1) {
                throw new InvalidArgumentException('contains an invalid document-local link');
            }

            return '#'.$this->uppercasePercentEscapes($fragment);
        }

        if ($value !== trim($value)
            || filter_var($value, FILTER_VALIDATE_URL) === false
            || strcasecmp((string) parse_url($value, PHP_URL_SCHEME), 'https') !== 0
            || ! is_string(parse_url($value, PHP_URL_HOST))) {
            throw new InvalidArgumentException('contains a link outside the HTTPS and document-fragment policy');
        }

        return 'https:'.substr($value, strlen((string) parse_url($value, PHP_URL_SCHEME)) + 1);
    }

    /** @param array<string, true> $selectedAssetIds */
    private function imageUrl(string $value, array $selectedAssetIds): string
    {
        if (str_starts_with(strtolower($value), 'data:')) {
            return $this->dataImageUrl($value);
        }

        $parts = parse_url($value);
        if ($parts === false) {
            throw new InvalidArgumentException('contains an invalid image source');
        }

        $path = $parts['path'] ?? '';
        if (isset($parts['query']) || isset($parts['fragment']) || preg_match('/\A\/brand-assets\/([0-9a-f-]{36})\z/iD', $path, $match) !== 1) {
            throw new InvalidArgumentException('contains an image outside the selected brand assets');
        }

        $id = strtolower($match[1]);
        if (! isset($selectedAssetIds[$id])) {
            throw new InvalidArgumentException('contains an image outside the selected brand assets');
        }

        if (isset($parts['scheme']) || isset($parts['host'])) {
            if (strcasecmp($parts['scheme'] ?? '', 'https') !== 0 || ! $this->isOwnedOrigin($parts)) {
                throw new InvalidArgumentException('contains an image outside the selected brand assets');
            }
        }

        return '/brand-assets/'.$id;
    }

    /** @param array<string, mixed> $parts */
    private function isOwnedOrigin(array $parts): bool
    {
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $origin = 'https://'.($parts['host'] ?? '').$port;

        foreach ([config('app.url'), config('capstan.artifacts.render_origin')] as $configured) {
            if (! is_string($configured)) {
                continue;
            }
            $configuredParts = parse_url($configured);
            if ($configuredParts === false || strcasecmp($configuredParts['scheme'] ?? '', 'https') !== 0) {
                continue;
            }
            $configuredPort = isset($configuredParts['port']) ? ':'.$configuredParts['port'] : '';
            if (strcasecmp($origin, 'https://'.($configuredParts['host'] ?? '').$configuredPort) === 0) {
                return true;
            }
        }

        return false;
    }

    private function dataImageUrl(string $value): string
    {
        $comma = strpos($value, ',');
        if ($comma === false) {
            throw new InvalidArgumentException('contains an invalid data image');
        }
        $metadata = substr($value, 5, $comma - 5);
        $payload = substr($value, $comma + 1);
        $parts = explode(';', $metadata);
        $mediaType = strtolower((string) array_shift($parts));
        if (preg_match('/\Aimage\/[a-z0-9][a-z0-9!#$&^_.+-]*\z/D', $mediaType) !== 1 || $payload === '') {
            throw new InvalidArgumentException('contains an invalid data image');
        }

        $base64 = false;
        $parameters = [];
        foreach ($parts as $index => $parameter) {
            if (strcasecmp($parameter, 'base64') === 0 && $index === array_key_last($parts)) {
                $base64 = true;

                continue;
            }
            if (preg_match('/\A([a-z0-9!#$&^_.+-]+)=([a-z0-9!#$&^_.+%-]+)\z/iD', $parameter, $match) !== 1
                || ! $this->hasValidPercentEscapes($match[2])) {
                throw new InvalidArgumentException('contains an invalid data image');
            }
            $parameters[] = ';'.strtolower($match[1]).'='.$this->uppercasePercentEscapes($match[2]);
        }

        if ($base64) {
            $decoded = base64_decode($payload, true);
            if (! is_string($decoded) || $decoded === '') {
                throw new InvalidArgumentException('contains an invalid data image');
            }
            $payload = base64_encode($decoded);
        } elseif (preg_match('/\A[A-Za-z0-9!$&\'()*+,\-.\/:;=?@_~%]+\z/D', $payload) !== 1 || ! $this->hasValidPercentEscapes($payload)) {
            throw new InvalidArgumentException('contains an invalid data image');
        } else {
            $payload = $this->uppercasePercentEscapes($payload);
        }

        return 'data:'.$mediaType.implode('', $parameters).($base64 ? ';base64' : '').','.$payload;
    }

    private function hasValidPercentEscapes(string $value): bool
    {
        return preg_match('/%(?![0-9A-Fa-f]{2})/', $value) !== 1;
    }

    private function uppercasePercentEscapes(string $value): string
    {
        return (string) preg_replace_callback('/%[0-9A-Fa-f]{2}/', static fn (array $match): string => strtoupper($match[0]), $value);
    }

    private function assertXmlText(string $value): void
    {
        if (! mb_check_encoding($value, 'UTF-8')
            || preg_match('/\A[\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]*\z/uD', $value) !== 1) {
            throw new InvalidArgumentException('must contain XML-compatible UTF-8 text');
        }
    }

    private function serializeFragment(DOMDocumentFragment $fragment): string
    {
        $html = '';
        foreach ($fragment->childNodes as $node) {
            $html .= $this->serializeNode($node);
        }

        return $html;
    }

    private function serializeNode(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return htmlspecialchars($node->nodeValue ?? '', ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        if (! $node instanceof DOMElement) {
            throw new InvalidArgumentException('contains an unsupported node');
        }

        $name = strtolower($node->tagName);
        $attributes = [];
        foreach ($node->attributes as $attribute) {
            $attributes[strtolower($attribute->nodeName)] = $attribute->value;
        }
        ksort($attributes);
        $opening = '<'.$name;
        foreach ($attributes as $attribute => $value) {
            $opening .= ' '.$attribute.'="'.htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8').'"';
        }
        if (in_array($name, self::VOID_ELEMENTS, true)) {
            return $opening.' />';
        }

        $content = '';
        foreach ($node->childNodes as $child) {
            $content .= $this->serializeNode($child);
        }

        return $opening.'>'.$content.'</'.$name.'>';
    }
}
