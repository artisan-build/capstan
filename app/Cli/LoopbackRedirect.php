<?php

namespace App\Cli;

final class LoopbackRedirect
{
    /** @var list<string> */
    private const array ALLOWED_HOSTS = ['127.0.0.1', 'localhost', '[::1]'];

    public static function isValid(string $uri): bool
    {
        if ($uri === '') {
            return false;
        }

        if (str_contains($uri, '\\') || preg_match('/%5c/i', $uri) === 1 || self::hasRawUserinfo($uri)) {
            return false;
        }

        $parts = parse_url($uri);

        if ($parts === false) {
            return false;
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if ($scheme === null || mb_strtolower($scheme) !== 'http') {
            return false;
        }

        if ($host === null) {
            return false;
        }

        return in_array(mb_strtolower($host), self::ALLOWED_HOSTS, true);
    }

    private static function hasRawUserinfo(string $uri): bool
    {
        $schemeEnd = strpos($uri, '://');

        if ($schemeEnd === false) {
            return false;
        }

        $authorityStart = $schemeEnd + 3;
        $authorityEnd = self::firstPosition($uri, ['/', '?', '#'], $authorityStart);
        $authority = $authorityEnd === null
            ? substr($uri, $authorityStart)
            : substr($uri, $authorityStart, $authorityEnd - $authorityStart);

        return str_contains($authority, '@');
    }

    /** @param list<string> $needles */
    private static function firstPosition(string $haystack, array $needles, int $offset): ?int
    {
        $positions = [];

        foreach ($needles as $needle) {
            $position = strpos($haystack, $needle, $offset);

            if ($position !== false) {
                $positions[] = $position;
            }
        }

        return $positions === [] ? null : min($positions);
    }

    /** @param array<string, string> $parameters */
    public static function appendQuery(string $uri, array $parameters): string
    {
        [$baseUri, $fragment] = array_pad(explode('#', $uri, 2), 2, null);
        $separator = str_contains($baseUri, '?') ? '&' : '?';
        $withQuery = $baseUri.$separator.http_build_query($parameters);

        return $fragment === null ? $withQuery : $withQuery.'#'.$fragment;
    }
}
