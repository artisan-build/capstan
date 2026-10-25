<?php

namespace App\Work;

use Illuminate\Validation\Rule;

final class WorkDrainItemShape
{
    public const string REPOSITORY_V1 = 'repository-v1';

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'item_shape' => ['sometimes', 'required', 'string', Rule::in([self::REPOSITORY_V1])],
        ];
    }

    /** @param array<string, mixed> $validated */
    public static function includesRepository(array $validated): bool
    {
        return ($validated['item_shape'] ?? null) === self::REPOSITORY_V1;
    }
}
