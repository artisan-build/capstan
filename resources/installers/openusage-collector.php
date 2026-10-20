<?php

declare(strict_types=1);

const CAPSTAN_MAX_SOURCE_BYTES = 1048576;
const CAPSTAN_MAX_STDERR_BYTES = 4096;
const CAPSTAN_MAX_DEPTH = 8;
const CAPSTAN_MAX_MAP_ENTRIES = 64;
const CAPSTAN_MAX_SNAPSHOTS = 32;
const CAPSTAN_MAX_WINDOWS = 8;

/** @return array{stdout: string, exit: int, timed_out: bool, oversized: bool} */
function capstanRun(array $command, int $wallSeconds, int $suffixAllowance = 0): array
{
    if (! function_exists('pcntl_exec') || ! function_exists('posix_setsid') || ! function_exists('posix_kill')) {
        return ['stdout' => '', 'exit' => 126, 'timed_out' => false, 'oversized' => false];
    }
    try {
        $encodedCommand = json_encode($command, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return ['stdout' => '', 'exit' => 126, 'timed_out' => false, 'oversized' => false];
    }
    $launcher = <<<'PHP'
$command = json_decode($argv[1], true, 4, JSON_THROW_ON_ERROR);
if (!is_array($command) || !array_is_list($command) || $command === []) {
    exit(126);
}
foreach ($command as $argument) {
    if (!is_string($argument)) {
        exit(126);
    }
}
if (posix_setsid() === -1) {
    exit(126);
}
pcntl_exec($command[0], array_slice($command, 1));
exit(127);
PHP;
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-d', 'auto_prepend_file=', '-r', $launcher, $encodedCommand], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, null, ['bypass_shell' => true]);

    if (! is_resource($process)) {
        return ['stdout' => '', 'exit' => 127, 'timed_out' => false, 'oversized' => false];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $status = proc_get_status($process);
    $pid = is_int($status['pid'] ?? null) ? $status['pid'] : null;

    $stdout = '';
    $stderrBytes = 0;
    $oversized = false;
    $timedOut = false;
    $knownExit = null;
    $deadline = microtime(true) + $wallSeconds;

    while (true) {
        $status = proc_get_status($process);
        $running = (bool) ($status['running'] ?? false);
        if (! $running && is_int($status['exitcode'] ?? null) && $status['exitcode'] >= 0) {
            $knownExit = $status['exitcode'];
        }
        $read = [];
        if (! feof($pipes[1])) {
            $read[] = $pipes[1];
        }
        if (! feof($pipes[2])) {
            $read[] = $pipes[2];
        }

        if ($read !== []) {
            $write = null;
            $except = null;
            @stream_select($read, $write, $except, 0, 100000);
            foreach ($read as $stream) {
                $chunk = (string) fread($stream, 8192);
                if ($stream === $pipes[1]) {
                    if (strlen($stdout) + strlen($chunk) > CAPSTAN_MAX_SOURCE_BYTES + $suffixAllowance) {
                        $oversized = true;
                    } elseif (! $oversized) {
                        $stdout .= $chunk;
                    }
                } else {
                    $stderrBytes = min(CAPSTAN_MAX_STDERR_BYTES, $stderrBytes + strlen($chunk));
                }
            }
        }

        if ($oversized || microtime(true) >= $deadline) {
            $timedOut = ! $oversized;
            if ($pid !== null) {
                @posix_kill(-$pid, 15);
                @time_nanosleep(0, 50_000_000);
                @posix_kill(-$pid, 9);
            }
            @proc_terminate($process, 9);
            break;
        }

        if (! $running && feof($pipes[1]) && feof($pipes[2])) {
            break;
        }
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit < 0 && $knownExit !== null) {
        $exit = $knownExit;
    }

    return ['stdout' => $stdout, 'exit' => $exit, 'timed_out' => $timedOut, 'oversized' => $oversized];
}

function capstanValidateTree(mixed $value, int $depth = 1): bool
{
    if ($depth > CAPSTAN_MAX_DEPTH) {
        return false;
    }
    if (is_string($value)) {
        return strlen($value) <= 256 && mb_check_encoding($value, 'UTF-8');
    }
    if (is_int($value) || is_bool($value) || $value === null) {
        return true;
    }
    if (is_float($value)) {
        return is_finite($value);
    }
    if (! is_array($value) || count($value) > CAPSTAN_MAX_MAP_ENTRIES) {
        return false;
    }
    foreach ($value as $key => $item) {
        if (is_string($key) && (strlen($key) > 64 || ! mb_check_encoding($key, 'UTF-8'))) {
            return false;
        }
        if (! capstanValidateTree($item, $depth + 1)) {
            return false;
        }
    }

    return true;
}

function capstanDecode(string $json): ?array
{
    if ($json === '' || strlen($json) > CAPSTAN_MAX_SOURCE_BYTES) {
        return null;
    }
    try {
        $value = json_decode($json, true, CAPSTAN_MAX_DEPTH, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }

    return is_array($value) && ! array_is_list($value) && capstanValidateTree($value) ? $value : null;
}

function capstanTimestamp(mixed $value): ?string
{
    if (! is_string($value) || strlen($value) > 64
        || preg_match(
            '/\A(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})T(?<hour>\d{2}):(?<minute>\d{2}):(?<second>\d{2})(?:\.\d+)?(?<offset>Z|[+-]\d{2}:\d{2})\z/D',
            $value,
            $parts,
        ) !== 1
        || (int) $parts['year'] < 1
        || ! checkdate((int) $parts['month'], (int) $parts['day'], (int) $parts['year'])
        || (int) $parts['hour'] > 23
        || (int) $parts['minute'] > 59
        || (int) $parts['second'] > 59
        || ($parts['offset'] !== 'Z' && ((int) substr($parts['offset'], 1, 2) > 23 || (int) substr($parts['offset'], 4, 2) > 59))) {
        return null;
    }
    try {
        $date = new DateTimeImmutable($value);
    } catch (Throwable) {
        return null;
    }

    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

function capstanPercent(mixed $value): ?float
{
    if (! is_int($value) && ! is_float($value)) {
        return null;
    }
    $number = (float) $value;
    if (! is_finite($number) || $number < 0 || $number > 100) {
        return null;
    }

    return round($number, 3, PHP_ROUND_HALF_UP);
}

function capstanStatus(mixed $value): string
{
    if (! is_string($value)) {
        return 'unknown';
    }
    $status = strtolower($value);

    return in_array($status, ['ok', 'near_limit', 'limited', 'auth_required', 'unsupported', 'error', 'unknown'], true)
        ? $status
        : 'unknown';
}

function capstanPlan(mixed $value): ?string
{
    return is_string($value) && $value !== '' && strlen($value) <= 32 && mb_check_encoding($value, 'UTF-8')
        ? $value
        : null;
}

/** @param array<string, array<string, mixed>> $providers */
function capstanMergeProvider(array &$providers, string $provider, ?string $plan, string $fetchedAt, ?bool $sourceStale, ?string $expiresAt, string $status, array $windows): void
{
    if (! isset($providers[$provider])) {
        $providers[$provider] = [
            'provider' => $provider,
            'plan' => $plan,
            'fetched_at' => $fetchedAt,
            'source_stale' => $sourceStale,
            'expires_at' => $expiresAt,
            'status' => $status,
            '_windows' => [],
        ];
    } else {
        $current = &$providers[$provider];
        if ($current['plan'] !== $plan) {
            $current['plan'] = null;
        }
        if (strcmp($fetchedAt, $current['fetched_at']) < 0) {
            $current['fetched_at'] = $fetchedAt;
        }
        $current['source_stale'] = $current['source_stale'] === null && $sourceStale === null
            ? null
            : $current['source_stale'] === true || $sourceStale === true;
        if ($expiresAt !== null && ($current['expires_at'] === null || strcmp($expiresAt, $current['expires_at']) < 0)) {
            $current['expires_at'] = $expiresAt;
        }
        if ($current['status'] !== $status) {
            $current['status'] = 'unknown';
        }
        unset($current);
    }

    foreach ($windows as $window) {
        $identity = implode('|', [$window['slot'], $window['kind'], (string) ($window['window_seconds'] ?? '')]);
        $current = $providers[$provider]['_windows'][$identity] ?? null;
        if ($current === null
            || $window['remaining_percent'] < $current['remaining_percent']
            || ($window['remaining_percent'] === $current['remaining_percent']
                && $window['resets_at'] !== null
                && ($current['resets_at'] === null || strcmp($window['resets_at'], $current['resets_at']) < 0))) {
            $providers[$provider]['_windows'][$identity] = $window;
        }
    }
}

/** @param array<string, array<string, mixed>> $providers */
function capstanFinish(string $source, ?string $sourceVersion, array $providers): ?array
{
    ksort($providers, SORT_STRING);
    $result = [];
    foreach ($providers as $provider) {
        ksort($provider['_windows'], SORT_STRING);
        $provider['windows'] = array_values($provider['_windows']);
        unset($provider['_windows']);
        if ($provider['windows'] !== [] && count($provider['windows']) <= CAPSTAN_MAX_WINDOWS) {
            $result[] = $provider;
        }
    }
    if ($result === [] || count($result) > 16) {
        return null;
    }

    return [
        'schema_version' => 1,
        'availability' => 'available',
        'source' => $source,
        'source_version' => $sourceVersion,
        'collected_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'providers' => $result,
    ];
}

function capstanAppFamily(string $id): ?string
{
    foreach (['claude', 'codex'] as $family) {
        if ($id === $family || str_starts_with($id, $family.':') || str_starts_with($id, $family.'-')) {
            return $family;
        }
    }

    return null;
}

function capstanNormalizeApp(string $json): ?array
{
    $data = capstanDecode($json);
    if ($data === null || ($data['schema'] ?? null) !== 'openusage.limits.v1'
        || ! isset($data['providers']) || ! is_array($data['providers']) || array_is_list($data['providers'])
        || count($data['providers']) > 16) {
        return null;
    }

    $slotMaps = [
        'claude' => ['session' => 'primary', 'weekly' => 'weekly', 'sonnet' => 'sonnet', 'fable' => 'fable'],
        'codex' => ['session' => 'primary', 'weekly' => 'secondary', 'spark' => 'spark', 'sparkWeekly' => 'spark_weekly'],
    ];
    $providers = [];
    foreach ($data['providers'] as $providerId => $record) {
        if (! is_string($providerId) || ! is_array($record) || array_is_list($record)) {
            continue;
        }
        $family = capstanAppFamily($providerId);
        $fetchedAt = capstanTimestamp($record['fetchedAt'] ?? null);
        $resources = $record['resources'] ?? null;
        if ($family === null || $fetchedAt === null || ! is_array($resources) || array_is_list($resources) || count($resources) > 64) {
            continue;
        }
        $windows = [];
        foreach ($slotMaps[$family] as $resourceId => $slot) {
            $resource = $resources[$resourceId] ?? null;
            if (! is_array($resource) || array_is_list($resource) || ($resource['unit'] ?? null) !== 'percent') {
                continue;
            }
            $kind = $resource['kind'] ?? null;
            $remaining = capstanPercent($resource['remaining'] ?? null);
            if (! in_array($kind, ['consumption', 'balance'], true) || $remaining === null) {
                continue;
            }
            $duration = $resource['windowSeconds'] ?? null;
            if ($kind === 'consumption' && (! is_int($duration) || $duration < 1 || $duration > 2678400)) {
                continue;
            }
            if ($kind === 'balance') {
                $duration = null;
            }
            $reset = array_key_exists('resetsAt', $resource) ? capstanTimestamp($resource['resetsAt']) : null;
            if (array_key_exists('resetsAt', $resource) && $reset === null) {
                continue;
            }
            $windows[] = [
                'slot' => $slot,
                'kind' => $kind,
                'window_seconds' => $duration,
                'remaining_percent' => $remaining,
                'resets_at' => $reset,
            ];
        }
        if ($windows === []) {
            continue;
        }
        $stale = $record['stale'] ?? null;
        $expires = capstanTimestamp($record['expiresAt'] ?? null);
        if (! is_bool($stale) || $expires === null) {
            continue;
        }
        capstanMergeProvider($providers, $family, capstanPlan($record['plan'] ?? null), $fetchedAt, $stale, $expires, 'ok', $windows);
    }

    return capstanFinish('openusage-app', null, $providers);
}

function capstanDuration(mixed $value): ?int
{
    if (! is_string($value)
        || preg_match('/\A(?:(\d{1,2})d)?(?:(\d{1,2})h)?(?:(\d{1,2})m)?\z/D', $value, $match, PREG_UNMATCHED_AS_NULL) !== 1
        || $value === '') {
        return null;
    }
    $days = $match[1] === null ? 0 : (int) $match[1];
    $hours = $match[2] === null ? 0 : (int) $match[2];
    $minutes = $match[3] === null ? 0 : (int) $match[3];
    if (($match[1] !== null && ($days < 1 || $days > 31))
        || ($match[2] !== null && ($hours < 1 || $hours > 23))
        || ($match[3] !== null && ($minutes < 1 || $minutes > 59))) {
        return null;
    }
    $seconds = $days * 86400 + $hours * 3600 + $minutes * 60;

    return $seconds >= 60 && $seconds <= 2678400 ? $seconds : null;
}

function capstanMetricWindow(array $metric, string $slot, ?int $fixedDuration, mixed $reset): ?array
{
    if (($metric['unit'] ?? null) !== '%' || (! is_int($metric['limit'] ?? null) && ! is_float($metric['limit'] ?? null))
        || abs((float) $metric['limit'] - 100.0) > 0.0000001) {
        return null;
    }
    $remaining = array_key_exists('remaining', $metric) ? capstanPercent($metric['remaining']) : null;
    $used = array_key_exists('used', $metric) ? capstanPercent($metric['used']) : null;
    if ($remaining === null && $used === null) {
        return null;
    }
    if ($remaining !== null && $used !== null && abs(($remaining + $used) - 100.0) > 0.001) {
        return null;
    }
    $remaining ??= round(100.0 - (float) $used, 3, PHP_ROUND_HALF_UP);
    $duration = $fixedDuration ?? capstanDuration($metric['window'] ?? null);
    if ($duration === null) {
        return null;
    }
    $resetsAt = $reset === null ? null : capstanTimestamp($reset);
    if ($reset !== null && $resetsAt === null) {
        return null;
    }

    return [
        'slot' => $slot,
        'kind' => 'consumption',
        'window_seconds' => $duration,
        'remaining_percent' => $remaining,
        'resets_at' => $resetsAt,
    ];
}

function capstanNormalizeCli(string $json): ?array
{
    $data = capstanDecode($json);
    if ($data === null || ($data['schema_version'] ?? null) !== '1'
        || ! isset($data['snapshots']) || ! is_array($data['snapshots']) || ! array_is_list($data['snapshots'])
        || count($data['snapshots']) > CAPSTAN_MAX_SNAPSHOTS) {
        return null;
    }
    $version = $data['openusage_version'] ?? null;
    if ($version !== null && (! is_string($version) || strlen($version) > 32 || preg_match('/\A[\x20-\x7E]+\z/D', $version) !== 1)) {
        return null;
    }

    $claude = [
        'usage_five_hour' => ['primary', 18000],
        'usage_seven_day' => ['weekly', 604800],
        'usage_seven_day_sonnet' => ['sonnet', 604800],
        'usage_seven_day_opus' => ['opus', 604800],
        'usage_seven_day_cowork' => ['cowork', 604800],
    ];
    $codex = [
        'rate_limit_primary' => ['primary', null],
        'rate_limit_secondary' => ['secondary', null],
    ];
    $providers = [];
    foreach ($data['snapshots'] as $snapshot) {
        if (! is_array($snapshot) || array_is_list($snapshot)) {
            continue;
        }
        $providerId = $snapshot['provider_id'] ?? null;
        $family = $providerId === 'claude_code' ? 'claude' : ($providerId === 'codex' ? 'codex' : null);
        $mapping = $family === 'claude' ? $claude : ($family === 'codex' ? $codex : null);
        $fetchedAt = capstanTimestamp($snapshot['timestamp'] ?? null);
        $metrics = $snapshot['metrics'] ?? null;
        $resets = $snapshot['resets'] ?? [];
        if ($family === null || $mapping === null || $fetchedAt === null
            || ! is_array($metrics) || array_is_list($metrics) || count($metrics) > 64
            || ! is_array($resets) || (array_is_list($resets) && $resets !== []) || count($resets) > 64) {
            continue;
        }
        $windows = [];
        foreach ($mapping as $metricId => [$slot, $duration]) {
            $metric = $metrics[$metricId] ?? null;
            if (! is_array($metric) || array_is_list($metric)) {
                continue;
            }
            $window = capstanMetricWindow($metric, $slot, $duration, $resets[$metricId] ?? null);
            if ($window !== null) {
                $windows[] = $window;
            }
        }
        if ($windows === []) {
            continue;
        }
        $attributes = $snapshot['attributes'] ?? [];
        $plan = is_array($attributes) && ! array_is_list($attributes) ? capstanPlan($attributes['plan_type'] ?? null) : null;
        capstanMergeProvider($providers, $family, $plan, $fetchedAt, null, null, capstanStatus($snapshot['status'] ?? null), $windows);
    }

    return capstanFinish('openusage-sh', $version, $providers);
}

function capstanUnavailable(string $failure): array
{
    return [
        'schema_version' => 1,
        'availability' => 'unavailable',
        'source' => 'none',
        'source_version' => null,
        'collected_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'providers' => [],
        'failure' => $failure,
    ];
}

function capstanSafeCachedReport(mixed $value): ?array
{
    if (! is_array($value) || array_is_list($value) || ! capstanValidateTree($value)
        || ($value['schema_version'] ?? null) !== 1
        || ! in_array($value['availability'] ?? null, ['available', 'unavailable'], true)
        || capstanTimestamp($value['collected_at'] ?? null) === null) {
        return null;
    }
    $availability = $value['availability'];
    if ($availability === 'unavailable') {
        $allowed = ['schema_version', 'availability', 'source', 'source_version', 'collected_at', 'providers', 'failure'];
        if (array_diff(array_keys($value), $allowed) !== [] || ($value['source'] ?? null) !== 'none'
            || ($value['source_version'] ?? null) !== null || ($value['providers'] ?? null) !== []
            || ! in_array($value['failure'] ?? null, ['not_found', 'timeout', 'incompatible', 'invalid_output'], true)) {
            return null;
        }

        $report = capstanUnavailable($value['failure']);
        $report['collected_at'] = capstanTimestamp($value['collected_at']);

        return $report;
    }

    $allowed = ['schema_version', 'availability', 'source', 'source_version', 'collected_at', 'providers'];
    $source = $value['source'] ?? null;
    $version = $value['source_version'] ?? null;
    $providerValues = $value['providers'] ?? null;
    if (array_diff(array_keys($value), $allowed) !== []
        || ! in_array($source, ['openusage-app', 'openusage-sh'], true)
        || ($version !== null && (! is_string($version) || strlen($version) > 32 || preg_match('/\A[\x20-\x7E]+\z/D', $version) !== 1))
        || ! is_array($providerValues) || ! array_is_list($providerValues)
        || $providerValues === [] || count($providerValues) > 16) {
        return null;
    }

    $providers = [];
    foreach ($providerValues as $provider) {
        if (! is_array($provider) || array_is_list($provider)
            || array_diff(array_keys($provider), ['provider', 'plan', 'fetched_at', 'source_stale', 'expires_at', 'status', 'windows']) !== []) {
            return null;
        }
        $id = $provider['provider'] ?? null;
        $fetchedAt = capstanTimestamp($provider['fetched_at'] ?? null);
        $sourceStale = $provider['source_stale'] ?? null;
        $expiresAt = ($provider['expires_at'] ?? null) === null ? null : capstanTimestamp($provider['expires_at']);
        $windows = $provider['windows'] ?? null;
        if (! in_array($id, ['claude', 'codex'], true) || $fetchedAt === null
            || ($source === 'openusage-app' && (! is_bool($sourceStale) || $expiresAt === null))
            || ($source === 'openusage-sh' && ($sourceStale !== null || $expiresAt !== null))
            || ! is_array($windows) || ! array_is_list($windows) || $windows === [] || count($windows) > CAPSTAN_MAX_WINDOWS) {
            return null;
        }
        $normalizedWindows = [];
        foreach ($windows as $window) {
            if (! is_array($window) || array_is_list($window)
                || array_diff(array_keys($window), ['slot', 'kind', 'window_seconds', 'remaining_percent', 'resets_at']) !== []) {
                return null;
            }
            $slot = $window['slot'] ?? null;
            $kind = $window['kind'] ?? null;
            $duration = $window['window_seconds'] ?? null;
            $remaining = capstanPercent($window['remaining_percent'] ?? null);
            $reset = ($window['resets_at'] ?? null) === null ? null : capstanTimestamp($window['resets_at']);
            if (! is_string($slot) || preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D', $slot) !== 1
                || ! in_array($kind, ['consumption', 'balance'], true) || $remaining === null
                || ($kind === 'consumption' && (! is_int($duration) || $duration < 1 || $duration > 2678400))
                || ($kind === 'balance' && $duration !== null)
                || (($window['resets_at'] ?? null) !== null && $reset === null)) {
                return null;
            }
            $normalizedWindows[] = [
                'slot' => $slot,
                'kind' => $kind,
                'window_seconds' => $duration,
                'remaining_percent' => $remaining,
                'resets_at' => $reset,
            ];
        }
        capstanMergeProvider(
            $providers,
            $id,
            capstanPlan($provider['plan'] ?? null),
            $fetchedAt,
            $sourceStale,
            $expiresAt,
            capstanStatus($provider['status'] ?? null),
            $normalizedWindows,
        );
    }

    $report = capstanFinish($source, $version, $providers);
    if ($report === null) {
        return null;
    }
    $report['collected_at'] = capstanTimestamp($value['collected_at']);

    return $report;
}

function capstanWriteCache(string $path, array $cache): void
{
    $json = json_encode($cache, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $temporary = $path.'.tmp.'.getmypid();
    if (file_put_contents($temporary, $json, LOCK_EX) === false) {
        return;
    }
    @chmod($temporary, 0600);
    @rename($temporary, $path);
    @chmod($path, 0600);
}

function capstanResolveOpenUsage(string $explicit): string
{
    $home = $_SERVER['HOME'] ?? $_ENV['HOME'] ?? null;
    $candidates = [$explicit];
    if (is_string($home) && str_starts_with($home, '/')) {
        $candidates[] = $home.'/.local/bin/openusage';
        $candidates[] = $home.'/bin/openusage';
    }
    array_push($candidates, '/opt/homebrew/bin/openusage', '/usr/local/bin/openusage', '/usr/bin/openusage');

    foreach ($candidates as $candidate) {
        if (! is_string($candidate) || ! str_starts_with($candidate, '/') || ! is_file($candidate) || ! is_executable($candidate)) {
            continue;
        }
        $resolved = realpath($candidate);
        if (is_string($resolved) && str_starts_with($resolved, '/') && is_file($resolved) && is_executable($resolved)) {
            return $resolved;
        }
    }

    return '';
}

$curl = $argv[1] ?? '';
$openusage = $argv[2] ?? '';
$cachePath = $argv[3] ?? '';
$cache = [];
if (is_file($cachePath) && filesize($cachePath) <= 131072) {
    try {
        $decoded = json_decode((string) file_get_contents($cachePath), true, CAPSTAN_MAX_DEPTH, JSON_THROW_ON_ERROR);
        if (is_array($decoded)) {
            $cache = $decoded;
        }
    } catch (Throwable) {
        $cache = [];
    }
}

$now = time();
$cached = capstanSafeCachedReport($cache['last_report'] ?? null);
if (is_int($cache['attempted_at'] ?? null) && $cache['attempted_at'] <= $now
    && $now - $cache['attempted_at'] < 300 && $cached !== null) {
    echo json_encode($cached, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit(0);
}

$failure = 'not_found';
$report = null;
if (is_string($curl) && str_starts_with($curl, '/') && is_file($curl) && is_executable($curl)) {
    $app = capstanRun([
        $curl, '--silent', '--show-error', '--connect-timeout', '1', '--max-time', '3',
        '--max-redirs', '0', '--noproxy', '*', '--max-filesize', (string) CAPSTAN_MAX_SOURCE_BYTES,
        '--user-agent', 'capstan-machine/1', '--write-out', "\nCAPSTAN_HTTP_STATUS:%{http_code}",
        'http://127.0.0.1:6736/v1/limits',
    ], 4, 32);
    if ($app['timed_out'] || $app['exit'] === 28) {
        $failure = 'timeout';
    } elseif ($app['oversized']) {
        $failure = 'invalid_output';
    } else {
        $markerText = "\nCAPSTAN_HTTP_STATUS:";
        $marker = strrpos($app['stdout'], $markerText);
        $status = $marker === false ? '' : substr($app['stdout'], $marker + strlen($markerText));
        $body = $marker === false ? '' : substr($app['stdout'], 0, $marker);
        if ($app['exit'] === 0 && $status === '200') {
            $report = capstanNormalizeApp($body);
            if ($report === null) {
                $failure = 'invalid_output';
            }
        } elseif (preg_match('/\A3\d\d\z/D', $status) === 1) {
            $failure = 'incompatible';
        } elseif ($app['exit'] !== 7) {
            $failure = 'invalid_output';
        }
    }
}

if ($report === null) {
    $openusage = capstanResolveOpenUsage(is_string($openusage) ? $openusage : '');
}
if ($report === null && $openusage !== '') {
    $cli = capstanRun([$openusage, 'export', '--output', '-', '--format', 'json', '--source', 'auto'], 30);
    if ($cli['timed_out']) {
        $failure = 'timeout';
    } elseif ($cli['oversized']) {
        $failure = 'invalid_output';
    } elseif ($cli['exit'] === 0) {
        $report = capstanNormalizeCli($cli['stdout']);
        if ($report === null) {
            $failure = 'invalid_output';
        }
    }
}

$report ??= capstanUnavailable($failure);
$lastGood = $report['availability'] === 'available' ? $report : capstanSafeCachedReport($cache['last_good'] ?? null);
if (($lastGood['availability'] ?? null) !== 'available') {
    $lastGood = null;
}
$newCache = ['attempted_at' => $now, 'last_report' => $report, 'last_good' => $lastGood];
capstanWriteCache($cachePath, $newCache);
echo json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
