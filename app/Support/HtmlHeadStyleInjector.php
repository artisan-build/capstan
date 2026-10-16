<?php

namespace App\Support;

final class HtmlHeadStyleInjector
{
    public function wrapBody(string $html, string $before, string $after, string $contentType): string
    {
        $cursor = 0;
        $bodyStart = null;
        while (($start = strpos($html, '<', $cursor)) !== false) {
            if (substr($html, $start, 4) === '<!--') {
                $end = strpos($html, '-->', $start + 4);
                if ($end === false) {
                    break;
                }
                $cursor = $end + 3;

                continue;
            }
            $end = $this->tagEnd($html, $start);
            if ($end === null) {
                break;
            }
            $inside = trim(substr($html, $start + 1, $end - $start - 1));
            $namePattern = $contentType === 'text/html' ? 'body' : '(?:[a-z][a-z0-9_-]*:)?body';
            if (preg_match('/^\/'.$namePattern.'\s*$/i', $inside) === 1 && $bodyStart !== null) {
                return substr($html, 0, $bodyStart).$before.substr($html, $bodyStart, $start - $bodyStart).$after.substr($html, $start);
            }
            if (preg_match('/^([a-z][a-z0-9:-]*)(?:\s|\/|$)/i', $inside, $match) === 1) {
                $name = strtolower($match[1]);
                if ($contentType !== 'text/html') {
                    $name = $this->localName($name);
                }
                // These HTML insertion states are outside this bounded scanner.
                // Preserve the document instead of placing a shell in a template
                // or in an escaped/double-escaped script.
                if ($contentType === 'text/html' && $name === 'template') {
                    return $html;
                }
                if ($name === 'body') {
                    $bodyStart ??= $end + 1;
                }
                if (in_array($name, self::RAW_TEXT_ELEMENTS, true)
                    && ($contentType === 'text/html' || ! str_ends_with($inside, '/'))) {
                    if ($contentType === 'text/html' && $name === 'plaintext') {
                        break;
                    }
                    $rawEnd = $this->rawTextEnd($html, $contentType === 'text/html' ? $name : $match[1], $end + 1);
                    if ($contentType === 'text/html' && $name === 'script'
                        && str_contains(substr($html, $end + 1, $rawEnd === null ? null : $rawEnd - $end), '<!--')) {
                        return $html;
                    }
                    if ($rawEnd === null) {
                        break;
                    }
                    $end = $rawEnd;
                }
            }
            $cursor = $end + 1;
        }

        // Do not move or reinterpret malformed document bytes. Fragments get a
        // shell; incomplete documents retain their original structure.
        if (preg_match('/<\s*(?:(?:[a-z][a-z0-9_-]*:)?(?:html|body)|!doctype)\b/i', $html) === 1) {
            return $html;
        }

        return $before.$html.$after;
    }

    /** @var list<string> */
    private const array RAW_TEXT_ELEMENTS = [
        'script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript', 'plaintext',
    ];

    public function inject(string $html, string $style, string $contentType = 'text/html'): string
    {
        $length = strlen($html);
        $cursor = 0;
        $doctypeEnd = null;
        $htmlStartEnd = null;
        $bodyStarted = false;
        $isHtml = $contentType === 'text/html';
        $htmlPrefix = '';

        while ($cursor < $length) {
            $tagStart = strpos($html, '<', $cursor);
            if ($tagStart === false) {
                break;
            }
            if (substr($html, $tagStart, 4) === '<!--') {
                $commentEnd = strpos($html, '-->', $tagStart + 4);
                if ($commentEnd === false) {
                    break;
                }
                $cursor = $commentEnd + 3;

                continue;
            }

            $tagEnd = $this->tagEnd($html, $tagStart);
            if ($tagEnd === null) {
                break;
            }
            $inside = ltrim(substr($html, $tagStart + 1, $tagEnd - $tagStart - 1));
            $lowerInside = mb_strtolower($inside);
            if (str_starts_with($lowerInside, '!doctype')) {
                $doctypeEnd ??= $tagEnd + 1;
                $cursor = $tagEnd + 1;

                continue;
            }
            if ($inside === '' || in_array($inside[0], ['!', '?', '/'], true)) {
                $cursor = $tagEnd + 1;

                continue;
            }
            if (preg_match('/^([a-z][a-z0-9:-]*)/i', $inside, $matches) !== 1) {
                $cursor = $tagEnd + 1;

                continue;
            }

            $name = mb_strtolower($matches[1]);
            if (! $isHtml) {
                $name = $this->localName($name);
            }
            if ($isHtml && $name === 'template') {
                break;
            }
            if ($name === 'html') {
                $htmlStartEnd ??= $tagEnd + 1;
                $htmlPrefix = substr($matches[1], 0, strlen($matches[1]) - strlen($name));
            }
            if ($name === 'head' && ! $bodyStarted) {
                return substr($html, 0, $tagEnd + 1).$style.substr($html, $tagEnd + 1);
            }
            if ($name === 'body') {
                $bodyStarted = true;
            }
            if (in_array($name, self::RAW_TEXT_ELEMENTS, true)
                && ($isHtml || ! str_ends_with(rtrim($inside), '/'))) {
                // HTML's self-closing flag never closes these non-void elements.
                // Unlike other raw text, plaintext has no HTML closing token.
                if ($isHtml && $name === 'plaintext') {
                    break;
                }
                $closingEnd = $this->rawTextEnd($html, $isHtml ? $name : $matches[1], $tagEnd + 1);
                if ($isHtml && $name === 'script'
                    && str_contains(substr($html, $tagEnd + 1, $closingEnd === null ? null : $closingEnd - $tagEnd), '<!--')) {
                    break;
                }
                if ($closingEnd === null) {
                    break;
                }
                $cursor = $closingEnd + 1;

                continue;
            }

            $cursor = $tagEnd + 1;
        }

        if (! $isHtml && $htmlStartEnd !== null) {
            return substr($html, 0, $htmlStartEnd).'<'.$htmlPrefix.'head>'.$style.'</'.$htmlPrefix.'head>'.substr($html, $htmlStartEnd);
        }
        if ($doctypeEnd !== null) {
            return substr($html, 0, $doctypeEnd).$style.substr($html, $doctypeEnd);
        }

        return $style.$html;
    }

    private function rawTextEnd(string $html, string $name, int $offset): ?int
    {
        $needle = '</'.$name;
        while (($closingStart = stripos($html, $needle, $offset)) !== false) {
            $afterName = $closingStart + strlen($needle);
            $boundary = $html[$afterName] ?? '';
            if ($boundary === '>' || $boundary === '/' || ctype_space($boundary)) {
                return $this->tagEnd($html, $closingStart);
            }
            $offset = $afterName;
        }

        return null;
    }

    private function localName(string $name): string
    {
        $colon = strrpos($name, ':');

        return $colon === false ? $name : substr($name, $colon + 1);
    }

    private function tagEnd(string $html, int $tagStart): ?int
    {
        $quote = null;
        $length = strlen($html);
        for ($cursor = $tagStart + 1; $cursor < $length; $cursor++) {
            $character = $html[$cursor];
            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif ($character === '>') {
                return $cursor;
            }
        }

        return null;
    }
}
