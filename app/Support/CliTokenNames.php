<?php

namespace App\Support;

final class CliTokenNames
{
    private const string BASE_NAME = 'capstan-cli';

    public static function forLabel(?string $label): string
    {
        $label = self::sanitizeLabel($label);

        if ($label === null) {
            return self::BASE_NAME;
        }

        return self::BASE_NAME.' — '.$label;
    }

    public static function sanitizeLabel(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }

        $label = trim((string) preg_replace('/[[:cntrl:]]+/', '', $label));

        return $label === '' ? null : $label;
    }
}
