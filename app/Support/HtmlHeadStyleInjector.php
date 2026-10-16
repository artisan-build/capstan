<?php

namespace App\Support;

final class HtmlHeadStyleInjector
{
    /** @var list<string> */
    private const array RAW_TEXT_ELEMENTS = [
        'script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript', 'plaintext',
    ];

    public function inject(string $html, string $style, string $contentType = 'text/html'): string
    {
        $length = strlen($html);
        $cursor = 0;
        $doctypeEnd = null;
        $bodyStarted = false;
        $isHtml = $contentType === 'text/html';

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
                $closingEnd = $this->rawTextEnd($html, $name, $tagEnd + 1);
                if ($closingEnd === null) {
                    break;
                }
                $cursor = $closingEnd + 1;

                continue;
            }

            $cursor = $tagEnd + 1;
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
