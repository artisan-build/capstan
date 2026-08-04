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
        $pathStart = strpos($uri, '/', $authorityStart);
        $authority = $pathStart === false
            ? substr($uri, $authorityStart)
            : substr($uri, $authorityStart, $pathStart - $authorityStart);

        return str_contains($authority, '@');
    }

    /** @param array<string, string> $parameters */
    public static function appendQuery(string $uri, array $parameters): string
    {
        $separator = str_contains($uri, '?') ? '&' : '?';

        return $uri.$separator.http_build_query($parameters);
    }
}
