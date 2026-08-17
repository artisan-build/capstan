<?php

namespace App\Support;

use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * RFC 8785 canonical JSON restricted to values without floating-point numbers.
 */
final class JsonCanonicalizer
{
    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * @param  array<array-key, mixed>|stdClass  $value
     *
     * @throws JsonException
     */
    public static function encode(array|stdClass $value): string
    {
        return self::encodeValue($value);
    }

    private static function encodeValue(mixed $value): string
    {
        if (is_float($value)) {
            throw new InvalidArgumentException('Canonical postmaster JSON does not support floating-point numbers.');
        }

        if ($value instanceof stdClass) {
            return self::encodeObject(get_object_vars($value));
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return '['.implode(',', array_map(self::encodeValue(...), $value)).']';
            }

            return self::encodeObject($value);
        }

        if ($value === null || is_string($value) || is_int($value) || is_bool($value)) {
            return json_encode($value, self::JSON_FLAGS);
        }

        throw new InvalidArgumentException('Canonical postmaster JSON contains an unsupported value.');
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function encodeObject(array $value): string
    {
        /** @var list<array{key: string, value: mixed}> $properties */
        $properties = [];

        foreach ($value as $key => $item) {
            $properties[] = ['key' => (string) $key, 'value' => $item];
        }

        usort($properties, static function (array $left, array $right): int {
            $leftUtf16 = mb_convert_encoding($left['key'], 'UTF-16BE', 'UTF-8');
            $rightUtf16 = mb_convert_encoding($right['key'], 'UTF-16BE', 'UTF-8');

            return strcmp($leftUtf16, $rightUtf16);
        });

        $encoded = array_map(
            static fn (array $property): string => json_encode($property['key'], self::JSON_FLAGS).':'.self::encodeValue($property['value']),
            $properties,
        );

        return '{'.implode(',', $encoded).'}';
    }
}
