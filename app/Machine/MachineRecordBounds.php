<?php

namespace App\Machine;

use App\Models\MachineUsageProvider;
use App\Models\MachineUsageWindow;
use InvalidArgumentException;

final class MachineRecordBounds
{
    public const int MAX_PROFILE_ENTRIES = 25;

    public const int MAX_PROVIDERS = 16;

    public const int MAX_WINDOWS = 8;

    public const int MAX_IDENTIFIER_BYTES = 64;

    public const int MAX_WINDOW_SECONDS = 31 * 24 * 60 * 60;

    /** @param array<string, mixed> $attributes */
    public static function report(array $attributes): void
    {
        self::oneOf($attributes['source'] ?? null, ['openusage-app', 'openusage-sh', 'none'], 'usage source');
        self::oneOf($attributes['availability'] ?? null, ['available', 'unavailable'], 'usage availability');
        self::nullableAscii($attributes['source_version'] ?? null, 32, 'source version');
        self::nullableOneOf($attributes['failure_code'] ?? null, ['not_found', 'timeout', 'incompatible', 'invalid_output'], 'failure code');
    }

    /** @param array<string, mixed> $attributes */
    public static function provider(array $attributes, ?int $modelId = null): void
    {
        self::identifier($attributes['provider'] ?? null, 'provider');
        self::nullableUtf8($attributes['plan'] ?? null, 32, 'plan');
        self::oneOf($attributes['status'] ?? null, ['ok', 'near_limit', 'limited', 'auth_required', 'unsupported', 'error', 'unknown'], 'provider status');

        $reportId = $attributes['machine_usage_report_id'] ?? null;
        if (! is_int($reportId) && ! (is_string($reportId) && ctype_digit($reportId))) {
            throw new InvalidArgumentException('Usage provider requires a report.');
        }
        $count = MachineUsageProvider::query()
            ->where('machine_usage_report_id', (int) $reportId)
            ->when($modelId !== null, fn ($query) => $query->whereKeyNot($modelId))
            ->count();
        if ($count >= self::MAX_PROVIDERS) {
            throw new InvalidArgumentException('A usage report accepts at most 16 providers.');
        }
    }

    /** @param array<string, mixed> $attributes */
    public static function window(array $attributes, ?int $modelId = null): string
    {
        self::identifier($attributes['slot'] ?? null, 'window slot');
        self::oneOf($attributes['kind'] ?? null, ['consumption', 'balance'], 'window kind');
        self::percentage($attributes['remaining_percent'] ?? null);

        $kind = $attributes['kind'];
        $duration = $attributes['window_seconds'] ?? null;
        if ($kind === 'balance') {
            if ($duration !== null) {
                throw new InvalidArgumentException('A balance window has no duration.');
            }
        } elseif (! is_int($duration) || $duration < 1 || $duration > self::MAX_WINDOW_SECONDS) {
            throw new InvalidArgumentException('A consumption window duration must be between 1 second and 31 days.');
        }

        $providerId = $attributes['machine_usage_provider_id'] ?? null;
        if (! is_int($providerId) && ! (is_string($providerId) && ctype_digit($providerId))) {
            throw new InvalidArgumentException('Usage window requires a provider.');
        }
        $count = MachineUsageWindow::query()
            ->where('machine_usage_provider_id', (int) $providerId)
            ->when($modelId !== null, fn ($query) => $query->whereKeyNot($modelId))
            ->count();
        if ($count >= self::MAX_WINDOWS) {
            throw new InvalidArgumentException('A usage provider accepts at most 8 windows.');
        }

        return hash('sha256', json_encode([$attributes['slot'], $kind, $duration], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $attributes */
    public static function workProfile(array $attributes, string $prefix = ''): void
    {
        $roles = $attributes[$prefix.'roles'] ?? null;
        $capabilities = $attributes[$prefix.'capabilities'] ?? null;
        $mappings = $attributes[$prefix.'role_providers'] ?? null;
        $ceiling = $attributes[$prefix.'concurrency_ceiling'] ?? null;

        self::stringList($roles, $prefix.'roles');
        self::stringList($capabilities, $prefix.'capabilities');
        if (! is_array($mappings) || (array_is_list($mappings) && $mappings !== []) || count($mappings) > self::MAX_PROFILE_ENTRIES) {
            throw new InvalidArgumentException($prefix.'role providers must be a map with at most 25 entries.');
        }
        foreach ($mappings as $role => $provider) {
            if (! is_string($role) || ! in_array($role, $roles, true)) {
                throw new InvalidArgumentException($prefix.'role provider keys must name declared roles.');
            }
            self::identifier($provider, $prefix.'role provider');
        }
        if (! is_int($ceiling) || $ceiling < 1 || $ceiling > 25) {
            throw new InvalidArgumentException($prefix.'concurrency ceiling must be between 1 and 25.');
        }
    }

    /** @param array<string, mixed> $attributes */
    public static function presence(array $attributes): void
    {
        self::workProfile($attributes);
        $count = $attributes['current_claimed_item_count'] ?? null;
        if (! is_int($count) || $count < 0 || $count > $attributes['concurrency_ceiling']) {
            throw new InvalidArgumentException('Current claimed item count must be between zero and the concurrency ceiling.');
        }
    }

    /** @param array<string, mixed> $attributes */
    public static function audit(array $attributes): void
    {
        $oldKeys = ['old_enabled', 'old_roles', 'old_capabilities', 'old_role_providers', 'old_concurrency_ceiling'];
        $oldPresent = array_filter($oldKeys, fn (string $key): bool => ($attributes[$key] ?? null) !== null);
        if ($oldPresent !== [] && count($oldPresent) !== count($oldKeys)) {
            throw new InvalidArgumentException('An old Work profile is either complete or absent.');
        }
        if ($oldPresent !== []) {
            self::workProfile($attributes, 'old_');
        }
        self::workProfile($attributes, 'new_');
    }

    private static function stringList(mixed $value, string $field): void
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > self::MAX_PROFILE_ENTRIES) {
            throw new InvalidArgumentException($field.' must be a list with at most 25 entries.');
        }
        if (count(array_unique($value, SORT_STRING)) !== count($value)) {
            throw new InvalidArgumentException($field.' entries must be distinct.');
        }
        foreach ($value as $entry) {
            if (! is_string($entry) || $entry === '' || mb_strlen($entry) > 64) {
                throw new InvalidArgumentException($field.' entries must be nonempty strings of at most 64 characters.');
            }
        }
    }

    private static function identifier(mixed $value, string $field): void
    {
        if (! is_string($value) || strlen($value) > self::MAX_IDENTIFIER_BYTES
            || preg_match('/\A[a-z0-9][a-z0-9._-]*\z/D', $value) !== 1) {
            throw new InvalidArgumentException($field.' has an invalid identifier.');
        }
    }

    private static function percentage(mixed $value): void
    {
        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('Remaining percent must be finite.');
        }
        $encoded = is_int($value) || is_float($value) || is_string($value) ? (string) $value : '';
        if (preg_match('/\A(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,3})?\z/D', $encoded) !== 1
            || (float) $encoded < 0 || (float) $encoded > 100) {
            throw new InvalidArgumentException('Remaining percent must be between 0 and 100 with at most three decimal places.');
        }
    }

    /** @param list<string> $allowed */
    private static function oneOf(mixed $value, array $allowed, string $field): void
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($field.' is invalid.');
        }
    }

    /** @param list<string> $allowed */
    private static function nullableOneOf(mixed $value, array $allowed, string $field): void
    {
        if ($value !== null) {
            self::oneOf($value, $allowed, $field);
        }
    }

    private static function nullableAscii(mixed $value, int $max, string $field): void
    {
        if ($value !== null && (! is_string($value) || strlen($value) > $max || preg_match('/\A[\x20-\x7E]+\z/D', $value) !== 1)) {
            throw new InvalidArgumentException($field.' must be bounded ASCII.');
        }
    }

    private static function nullableUtf8(mixed $value, int $maxBytes, string $field): void
    {
        if ($value !== null && (! is_string($value) || $value === '' || strlen($value) > $maxBytes || ! mb_check_encoding($value, 'UTF-8'))) {
            throw new InvalidArgumentException($field.' must be bounded UTF-8.');
        }
    }
}
