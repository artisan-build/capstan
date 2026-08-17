<?php

namespace App\Support;

use InvalidArgumentException;
use JsonException;

/**
 * RFC 8785 canonical JSON restricted to values without floating-point numbers.
 */
final class JsonCanonicalizer
{
    /**
     * @param  array<array-key, mixed>  $value
     *
     * @throws JsonException
     */
    public static function encode(array $value): string
    {
        return json_encode(
            self::canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (is_float($value)) {
            throw new InvalidArgumentException('Canonical postmaster JSON does not support floating-point numbers.');
        }

        if (! is_array($value)) {
            if ($value === null || is_string($value) || is_int($value) || is_bool($value)) {
                return $value;
            }

            throw new InvalidArgumentException('Canonical postmaster JSON contains an unsupported value.');
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        $canonical = [];

        foreach ($value as $key => $item) {
            $canonical[(string) $key] = self::canonicalize($item);
        }

        uksort($canonical, static function (int|string $left, int|string $right): int {
            $leftUtf16 = mb_convert_encoding((string) $left, 'UTF-16BE', 'UTF-8');
            $rightUtf16 = mb_convert_encoding((string) $right, 'UTF-16BE', 'UTF-8');

            return strcmp($leftUtf16, $rightUtf16);
        });

        return $canonical;
    }
}
