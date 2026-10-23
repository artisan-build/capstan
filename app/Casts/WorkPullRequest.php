<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** @implements CastsAttributes<array{repo: string, number: int, url: string}|null, array<string, mixed>|null> */
final class WorkPullRequest implements CastsAttributes
{
    /** @return array{repo: string, number: int, url: string}|null */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? self::normalize($decoded) : null;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode(self::normalize((array) $value), JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $value
     * @return array{repo: string, number: int, url: string}
     */
    public static function normalize(array $value): array
    {
        return [
            'repo' => (string) ($value['repo'] ?? ''),
            'number' => (int) ($value['number'] ?? 0),
            'url' => (string) ($value['url'] ?? ''),
        ];
    }
}
