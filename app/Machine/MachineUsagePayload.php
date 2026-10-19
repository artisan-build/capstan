<?php

namespace App\Machine;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use stdClass;

final class MachineUsagePayload
{
    /**
     * @return array{
     *     source: string,
     *     source_version: string|null,
     *     availability: string,
     *     collected_at: CarbonImmutable,
     *     failure_code: string|null,
     *     providers: list<array{
     *         provider: string,
     *         plan: string|null,
     *         fetched_at: CarbonImmutable,
     *         source_stale: bool|null,
     *         expires_at: CarbonImmutable|null,
     *         status: string,
     *         windows: list<array{
     *             slot: string,
     *             kind: string,
     *             window_seconds: int|null,
     *             remaining_percent: float,
     *             resets_at: CarbonImmutable|null
     *         }>
     *     }>
     * }
     */
    public function parse(mixed $raw): array
    {
        if (! $raw instanceof stdClass) {
            throw new InvalidArgumentException('Usage must be a JSON object.');
        }
        $encoded = json_encode($raw, JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded) || strlen($encoded) > 65536) {
            throw new InvalidArgumentException('Usage exceeds 64 KiB.');
        }
        $this->boundedTree($raw);

        $data = get_object_vars($raw);
        $this->onlyKeys($data, ['schema_version', 'availability', 'source', 'source_version', 'collected_at', 'providers', 'failure']);
        if (($data['schema_version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Usage schema version is invalid.');
        }
        $availability = $this->enum($data['availability'] ?? null, ['available', 'unavailable']);
        $source = $this->enum($data['source'] ?? null, ['openusage-app', 'openusage-sh', 'none']);
        $sourceVersion = $data['source_version'] ?? null;
        if ($sourceVersion !== null && (! is_string($sourceVersion) || strlen($sourceVersion) > 32
            || preg_match('/\A[\x20-\x7E]+\z/D', $sourceVersion) !== 1)) {
            throw new InvalidArgumentException('Usage source version is invalid.');
        }
        $collectedAt = $this->timestamp($data['collected_at'] ?? null);
        $providers = $data['providers'] ?? null;
        if (! is_array($providers) || ! array_is_list($providers) || count($providers) > MachineRecordBounds::MAX_PROVIDERS) {
            throw new InvalidArgumentException('Usage providers are invalid.');
        }

        if ($availability === 'unavailable') {
            if ($source !== 'none' || $sourceVersion !== null || $providers !== []) {
                throw new InvalidArgumentException('Unavailable usage has an invalid shape.');
            }
            $failure = $this->enum($data['failure'] ?? null, ['not_found', 'timeout', 'incompatible', 'invalid_output']);

            return [
                'source' => $source,
                'source_version' => null,
                'availability' => $availability,
                'collected_at' => $collectedAt,
                'failure_code' => $failure,
                'providers' => [],
            ];
        }

        if ($source === 'none' || array_key_exists('failure', $data) || $providers === []) {
            throw new InvalidArgumentException('Available usage has an invalid shape.');
        }

        $normalized = [];
        $seenProviders = [];
        foreach ($providers as $provider) {
            if (! $provider instanceof stdClass) {
                throw new InvalidArgumentException('Usage provider must be an object.');
            }
            $attributes = get_object_vars($provider);
            $this->onlyKeys($attributes, ['provider', 'plan', 'fetched_at', 'source_stale', 'expires_at', 'status', 'windows']);
            $id = $this->identifier($attributes['provider'] ?? null);
            if (isset($seenProviders[$id])) {
                throw new InvalidArgumentException('Usage providers must be distinct.');
            }
            $seenProviders[$id] = true;
            $plan = $attributes['plan'] ?? null;
            if ($plan !== null && (! is_string($plan) || $plan === '' || strlen($plan) > 32 || ! mb_check_encoding($plan, 'UTF-8'))) {
                throw new InvalidArgumentException('Usage plan is invalid.');
            }
            $sourceStale = $attributes['source_stale'] ?? null;
            if ($sourceStale !== null && ! is_bool($sourceStale)) {
                throw new InvalidArgumentException('Usage source stale evidence is invalid.');
            }
            $expiresAt = ($attributes['expires_at'] ?? null) === null ? null : $this->timestamp($attributes['expires_at']);
            if ($source === 'openusage-sh' && ($sourceStale !== null || $expiresAt !== null)) {
                throw new InvalidArgumentException('OpenUsage.sh cannot supply stale or expiry evidence.');
            }
            $windows = $attributes['windows'] ?? null;
            if (! is_array($windows) || ! array_is_list($windows) || $windows === []
                || count($windows) > MachineRecordBounds::MAX_WINDOWS) {
                throw new InvalidArgumentException('Usage windows are invalid.');
            }

            $normalizedWindows = [];
            $seenWindows = [];
            foreach ($windows as $window) {
                if (! $window instanceof stdClass) {
                    throw new InvalidArgumentException('Usage window must be an object.');
                }
                $windowAttributes = get_object_vars($window);
                $this->onlyKeys($windowAttributes, ['slot', 'kind', 'window_seconds', 'remaining_percent', 'resets_at']);
                $slot = $this->identifier($windowAttributes['slot'] ?? null);
                $kind = $this->enum($windowAttributes['kind'] ?? null, ['consumption', 'balance']);
                $duration = $windowAttributes['window_seconds'] ?? null;
                if (($kind === 'balance' && $duration !== null)
                    || ($kind === 'consumption' && (! is_int($duration) || $duration < 1 || $duration > MachineRecordBounds::MAX_WINDOW_SECONDS))) {
                    throw new InvalidArgumentException('Usage window duration is invalid.');
                }
                $identity = $slot.'|'.$kind.'|'.(string) $duration;
                if (isset($seenWindows[$identity])) {
                    throw new InvalidArgumentException('Usage windows must be distinct.');
                }
                $seenWindows[$identity] = true;
                $remaining = $windowAttributes['remaining_percent'] ?? null;
                if ((! is_int($remaining) && ! is_float($remaining)) || ! is_finite((float) $remaining)
                    || $remaining < 0 || $remaining > 100
                    || preg_match('/\A(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,3})?\z/D', (string) $remaining) !== 1) {
                    throw new InvalidArgumentException('Usage remaining percent is invalid.');
                }
                $normalizedWindows[] = [
                    'slot' => $slot,
                    'kind' => $kind,
                    'window_seconds' => $duration,
                    'remaining_percent' => (float) $remaining,
                    'resets_at' => ($windowAttributes['resets_at'] ?? null) === null ? null : $this->timestamp($windowAttributes['resets_at']),
                ];
            }

            $normalized[] = [
                'provider' => $id,
                'plan' => $plan,
                'fetched_at' => $this->timestamp($attributes['fetched_at'] ?? null),
                'source_stale' => $sourceStale,
                'expires_at' => $expiresAt,
                'status' => $this->enum($attributes['status'] ?? null, ['ok', 'near_limit', 'limited', 'auth_required', 'unsupported', 'error', 'unknown']),
                'windows' => $normalizedWindows,
            ];
        }

        return [
            'source' => $source,
            'source_version' => $sourceVersion,
            'availability' => $availability,
            'collected_at' => $collectedAt,
            'failure_code' => null,
            'providers' => $normalized,
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $allowed
     */
    private function onlyKeys(array $value, array $allowed): void
    {
        if (array_diff(array_keys($value), $allowed) !== []) {
            throw new InvalidArgumentException('Usage contains unsupported fields.');
        }
    }

    /** @param list<string> $allowed */
    private function enum(mixed $value, array $allowed): string
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('Usage enum is invalid.');
        }

        return $value;
    }

    private function identifier(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 64 || preg_match('/\A[a-z0-9][a-z0-9._-]*\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Usage identifier is invalid.');
        }

        return $value;
    }

    private function timestamp(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || strlen($value) > 64
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Usage timestamp is invalid.');
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            throw new InvalidArgumentException('Usage timestamp is invalid.');
        }
    }

    private function boundedTree(mixed $value, int $depth = 1): void
    {
        if ($depth > 8) {
            throw new InvalidArgumentException('Usage nesting is too deep.');
        }
        if ($value instanceof stdClass) {
            $values = get_object_vars($value);
            if (count($values) > 64) {
                throw new InvalidArgumentException('Usage object is too large.');
            }
            foreach ($values as $key => $item) {
                if (strlen($key) > 64) {
                    throw new InvalidArgumentException('Usage key is too long.');
                }
                $this->boundedTree($item, $depth + 1);
            }

            return;
        }
        if (is_array($value)) {
            if (count($value) > 64) {
                throw new InvalidArgumentException('Usage array is too large.');
            }
            foreach ($value as $item) {
                $this->boundedTree($item, $depth + 1);
            }

            return;
        }
        if (is_string($value) && strlen($value) > 256) {
            throw new InvalidArgumentException('Usage scalar is too long.');
        }
        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('Usage number is invalid.');
        }
    }
}
