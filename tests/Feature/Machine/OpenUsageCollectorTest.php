<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

function usageCollectorDirectory(): string
{
    $directory = sys_get_temp_dir().'/capstan-usage-'.bin2hex(random_bytes(6));
    File::makeDirectory($directory, 0700, true);

    return $directory;
}

/** @return array<string, mixed> */
function runUsageCollector(string $directory, string $curlBody, ?string $cliBody = null, int $httpStatus = 200): array
{
    $curl = $directory.'/curl';
    File::put($curl, "#!/bin/sh\nprintf '%s' ".escapeshellarg($curlBody)."\nprintf '\\nCAPSTAN_HTTP_STATUS:{$httpStatus}'\n");
    chmod($curl, 0700);
    $openusage = '';
    if ($cliBody !== null) {
        $openusage = $directory.'/openusage';
        File::put($openusage, "#!/bin/sh\n[ \"\$#\" -eq 7 ] || exit 91\n[ \"\$1\" = export ] && [ \"\$2\" = --output ] && [ \"\$3\" = - ] && [ \"\$4\" = --format ] && [ \"\$5\" = json ] && [ \"\$6\" = --source ] && [ \"\$7\" = auto ] || exit 92\nprintf '%s' ".escapeshellarg($cliBody)."\n");
        chmod($openusage, 0700);
    }
    $process = new Process([
        PHP_BINARY,
        '-d',
        'auto_prepend_file=',
        resource_path('installers/openusage-collector.php'),
        $curl,
        $openusage,
        $directory.'/usage-cache.json',
    ]);
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

/** @return array{report: array<string, mixed>, cache: string} */
function runCustomUsageCollector(string $directory, string $curlScript, ?string $cliScript = null): array
{
    $curl = $directory.'/curl';
    File::put($curl, $curlScript);
    chmod($curl, 0700);
    $openusage = '';
    if ($cliScript !== null) {
        $openusage = $directory.'/openusage';
        File::put($openusage, $cliScript);
        chmod($openusage, 0700);
    }
    $cache = $directory.'/usage-cache.json';
    $process = new Process([
        PHP_BINARY,
        '-d',
        'auto_prepend_file=',
        resource_path('installers/openusage-collector.php'),
        $curl,
        $openusage,
        $cache,
    ]);
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    return [
        'report' => json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR),
        'cache' => File::get($cache),
    ];
}

test('the app adapter emits only fixed Claude and Codex quota slots', function (): void {
    $directory = usageCollectorDirectory();
    $canary = 'account-secret-canary';
    $body = json_encode([
        'schema' => 'openusage.limits.v1',
        'generatedAt' => '2026-10-19T18:00:00Z',
        'providers' => [
            'claude:'.$canary => [
                'displayName' => 'private@example.test',
                'plan' => 'Team 5x',
                'fetchedAt' => '2026-10-19T17:59:55+00:00',
                'expiresAt' => '2026-10-19T18:04:55Z',
                'stale' => false,
                'resources' => [
                    'session' => [
                        'kind' => 'consumption', 'unit' => 'percent', 'remaining' => 72.5555,
                        'windowSeconds' => 18000, 'resetsAt' => '2026-10-19T20:00:00+02:00',
                        'token' => $canary,
                    ],
                    'today' => ['kind' => 'balance', 'unit' => 'usd', 'remaining' => 10],
                ],
            ],
            'unregistered:'.$canary => [
                'plan' => $canary, 'fetchedAt' => '2026-10-19T17:59:55Z',
                'expiresAt' => '2026-10-19T18:04:55Z', 'stale' => false, 'resources' => [],
            ],
        ],
        'errors' => [['providerId' => $canary, 'message' => $canary]],
    ], JSON_THROW_ON_ERROR);

    try {
        $report = runUsageCollector($directory, $body);
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);
        $cache = File::get($directory.'/usage-cache.json');

        expect($report['schema_version'])->toBe(1)
            ->and($report['availability'])->toBe('available')
            ->and($report['source'])->toBe('openusage-app')
            ->and($report['providers'])->toBe([[
                'provider' => 'claude',
                'plan' => 'Team 5x',
                'fetched_at' => '2026-10-19T17:59:55Z',
                'source_stale' => false,
                'expires_at' => '2026-10-19T18:04:55Z',
                'status' => 'ok',
                'windows' => [[
                    'slot' => 'primary',
                    'kind' => 'consumption',
                    'window_seconds' => 18000,
                    'remaining_percent' => 72.556,
                    'resets_at' => '2026-10-19T18:00:00Z',
                ]],
            ]])
            ->and(fileperms($directory.'/usage-cache.json') & 0777)->toBe(0600)
            ->and($encoded)->not->toContain($canary)
            ->and($encoded)->not->toContain('private@example.test')
            ->and($cache)->not->toContain($canary)
            ->and($cache)->not->toContain('private@example.test');
    } finally {
        File::deleteDirectory($directory);
    }
});

test('the CLI adapter uses the exact export command and conservatively collapses accounts', function (): void {
    $directory = usageCollectorDirectory();
    $canary = 'forbidden-cli-canary';
    $snapshot = static fn (string $timestamp, float $remaining, string $plan): array => [
        'provider_id' => 'claude_code',
        'account_id' => $canary,
        'timestamp' => $timestamp,
        'status' => 'OK',
        'metrics' => [
            'usage_five_hour' => ['unit' => '%', 'limit' => 100, 'remaining' => $remaining, 'used' => 100 - $remaining],
            'usage_unknown' => ['unit' => '%', 'limit' => 100, 'remaining' => 99],
        ],
        'resets' => ['usage_five_hour' => '2026-10-19T20:00:00Z'],
        'attributes' => ['plan_type' => $plan, 'email' => 'private@example.test', 'path' => '/secret/path'],
        'diagnostics' => ['message' => $canary],
        'daily' => [['message' => $canary]],
    ];
    $body = json_encode([
        'schema_version' => '1',
        'openusage_version' => '0.26.1',
        'snapshots' => [
            $snapshot('2026-10-19T17:59:55Z', 80, 'pro'),
            $snapshot('2026-10-19T17:58:55Z', 70, 'team'),
            [
                'provider_id' => 'codex',
                'account_id' => $canary,
                'timestamp' => '2026-10-19T17:57:55Z',
                'status' => 'near_limit',
                'metrics' => [
                    'rate_limit_primary' => [
                        'unit' => '%', 'limit' => 100, 'used' => 25, 'remaining' => 75, 'window' => '1h30m',
                    ],
                    'rate_limit_primary_extra_pool' => ['unit' => '%', 'limit' => 100, 'remaining' => 100, 'window' => '1h'],
                ],
                'resets' => ['rate_limit_primary' => '2026-10-19T19:00:00+01:00'],
                'attributes' => ['plan_type' => 'team', 'organization' => $canary],
                'message' => $canary,
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    try {
        $report = runUsageCollector($directory, '{}', $body, 503);
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);

        expect($report['source'])->toBe('openusage-sh')
            ->and($report['source_version'])->toBe('0.26.1')
            ->and($report['providers'][0]['provider'])->toBe('claude')
            ->and($report['providers'][0]['plan'])->toBeNull()
            ->and($report['providers'][0]['fetched_at'])->toBe('2026-10-19T17:58:55Z')
            ->and($report['providers'][0]['windows'][0]['remaining_percent'])->toBe(70)
            ->and($report['providers'][1]['provider'])->toBe('codex')
            ->and($report['providers'][1]['windows'][0])->toBe([
                'slot' => 'primary',
                'kind' => 'consumption',
                'window_seconds' => 5400,
                'remaining_percent' => 75,
                'resets_at' => '2026-10-19T18:00:00Z',
            ])
            ->and($encoded)->not->toContain($canary)
            ->and($encoded)->not->toContain('private@example.test')
            ->and($encoded)->not->toContain('/secret/path');
    } finally {
        File::deleteDirectory($directory);
    }
});

test('invalid schemas percentages durations redirects and trailing JSON fail closed', function (string $body, ?string $cli, int $status, string $failure): void {
    $directory = usageCollectorDirectory();

    try {
        $report = runUsageCollector($directory, $body, $cli, $status);
        expect($report['availability'])->toBe('unavailable')
            ->and($report['providers'])->toBe([])
            ->and($report['failure'])->toBe($failure);
    } finally {
        File::deleteDirectory($directory);
    }
})->with([
    'redirect' => ['{}', null, 302, 'incompatible'],
    'app trailing document' => ['{"schema":"openusage.limits.v1"}{}', null, 200, 'invalid_output'],
    'CLI schema integer' => ['{}', '{"schema_version":1,"snapshots":[]}', 503, 'invalid_output'],
    'CLI inconsistent percent' => ['{}', json_encode([
        'schema_version' => '1',
        'snapshots' => [[
            'provider_id' => 'codex', 'timestamp' => '2026-10-19T18:00:00Z', 'status' => 'ok',
            'metrics' => ['rate_limit_primary' => ['unit' => '%', 'limit' => 100, 'used' => 40, 'remaining' => 70, 'window' => '1h']],
            'resets' => [], 'attributes' => [],
        ]],
    ], JSON_THROW_ON_ERROR), 503, 'invalid_output'],
    'CLI noncanonical duration' => ['{}', json_encode([
        'schema_version' => '1',
        'snapshots' => [[
            'provider_id' => 'codex', 'timestamp' => '2026-10-19T18:00:00Z', 'status' => 'ok',
            'metrics' => ['rate_limit_primary' => ['unit' => '%', 'limit' => 100, 'remaining' => 70, 'window' => '90m']],
            'resets' => [], 'attributes' => [],
        ]],
    ], JSON_THROW_ON_ERROR), 503, 'invalid_output'],
]);

test('a failed attempt retains the last good sanitized cache and reuses only the bounded failure report', function (): void {
    $directory = usageCollectorDirectory();
    $good = json_encode([
        'schema' => 'openusage.limits.v1',
        'providers' => [
            'codex' => [
                'plan' => 'team', 'fetchedAt' => '2026-10-19T18:00:00Z',
                'expiresAt' => '2026-10-19T18:05:00Z', 'stale' => false,
                'resources' => ['session' => [
                    'kind' => 'consumption', 'unit' => 'percent', 'remaining' => 80, 'windowSeconds' => 18000,
                ]],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    try {
        runUsageCollector($directory, $good);
        $cache = json_decode(File::get($directory.'/usage-cache.json'), true, flags: JSON_THROW_ON_ERROR);
        $cache['attempted_at'] = 0;
        File::put($directory.'/usage-cache.json', json_encode($cache, JSON_THROW_ON_ERROR));
        $failed = runUsageCollector($directory, 'not-json');
        $after = json_decode(File::get($directory.'/usage-cache.json'), true, flags: JSON_THROW_ON_ERROR);
        $reused = runUsageCollector($directory, $good);

        expect($failed['availability'])->toBe('unavailable')
            ->and($after['last_good']['availability'])->toBe('available')
            ->and($after['last_good']['providers'][0]['provider'])->toBe('codex')
            ->and($reused)->toBe($failed);
    } finally {
        File::deleteDirectory($directory);
    }
});

test('a poisoned cache is never relayed or retained', function (): void {
    $directory = usageCollectorDirectory();
    $canary = 'cache-secret-canary';
    $cache = $directory.'/usage-cache.json';
    File::put($cache, json_encode([
        'attempted_at' => time(),
        'last_report' => [
            'schema_version' => 1,
            'availability' => 'unavailable',
            'source' => 'none',
            'source_version' => null,
            'collected_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'providers' => [],
            'failure' => 'not_found',
            'token' => $canary,
        ],
        'last_good' => ['token' => $canary],
    ], JSON_THROW_ON_ERROR));

    try {
        $result = runCustomUsageCollector($directory, "#!/bin/sh\nexit 7\n");

        expect($result['report']['availability'])->toBe('unavailable')
            ->and(json_encode($result['report'], JSON_THROW_ON_ERROR))->not->toContain($canary)
            ->and($result['cache'])->not->toContain($canary);
    } finally {
        File::deleteDirectory($directory);
    }
});

test('refusal timeout oversize depth and stderr failures stay bounded and sanitized', function (string $case, string $expected): void {
    $directory = usageCollectorDirectory();
    $canary = 'stderr-private-account-canary';
    $curl = match ($case) {
        'refused' => "#!/bin/sh\nexit 7\n",
        'timeout' => "#!/bin/sh\nexit 28\n",
        'oversize' => "#!/bin/sh\n/bin/dd if=/dev/zero bs=1048600 count=1 2>/dev/null | /usr/bin/tr '\\000' x\n",
        'depth' => "#!/bin/sh\nprintf '%s\\nCAPSTAN_HTTP_STATUS:200' ".escapeshellarg('{"schema":"openusage.limits.v1","providers":{"claude":{"fetchedAt":"2026-10-19T18:00:00Z","expiresAt":"2026-10-19T18:05:00Z","stale":false,"resources":{"session":{"kind":"consumption","unit":"percent","remaining":50,"windowSeconds":18000,"extra":{"a":{"b":{"c":{"d":{"e":{"f":true}}}}}}}}}}}')."\n",
        'stderr' => "#!/bin/sh\nexit 7\n",
    };
    $cli = $case === 'stderr' ? "#!/bin/sh\nprintf '%s' ".escapeshellarg($canary)." >&2\nexit 1\n" : null;

    try {
        $result = runCustomUsageCollector($directory, $curl, $cli);
        expect($result['report']['availability'])->toBe('unavailable')
            ->and($result['report']['failure'])->toBe($expected)
            ->and($result['report']['providers'])->toBe([])
            ->and(json_encode($result['report'], JSON_THROW_ON_ERROR))->not->toContain($canary)
            ->and($result['cache'])->not->toContain($canary);
    } finally {
        File::deleteDirectory($directory);
    }
})->with([
    'refused connection' => ['refused', 'not_found'],
    'curl timeout' => ['timeout', 'timeout'],
    'oversized response' => ['oversize', 'invalid_output'],
    'deep response' => ['depth', 'invalid_output'],
    'stderr discarded' => ['stderr', 'not_found'],
]);

test('the standalone collector terminates the app process group at its wall clock', function (): void {
    $directory = usageCollectorDirectory();

    try {
        expect(function_exists('posix_kill'))->toBeTrue()
            ->and(function_exists('posix_setpgid'))->toBeTrue();
        $startedAt = microtime(true);
        $result = runCustomUsageCollector($directory, "#!/bin/sh\nsleep 20\n");
        $elapsed = microtime(true) - $startedAt;

        expect($result['report']['availability'])->toBe('unavailable')
            ->and($result['report']['failure'])->toBe('timeout')
            ->and($result['report']['providers'])->toBe([])
            ->and($elapsed)->toBeGreaterThanOrEqual(3.0)
            ->and($elapsed)->toBeLessThan(8.0);
    } finally {
        File::deleteDirectory($directory);
    }
});

test('the collector pins the fixed loopback and execution bounds', function (): void {
    $source = File::get(resource_path('installers/openusage-collector.php'));

    expect($source)->toContain("'--connect-timeout', '1', '--max-time', '3'")
        ->and($source)->toContain("'--max-redirs', '0'")
        ->and($source)->toContain("'http://127.0.0.1:6736/v1/limits'")
        ->and($source)->toContain('[$openusage, \'export\', \'--output\', \'-\', \'--format\', \'json\', \'--source\', \'auto\'], 30')
        ->and($source)->not->toContain('https://api.anthropic.com')
        ->and($source)->not->toContain('api.openai.com')
        ->and($source)->not->toContain('Illuminate\\')
        ->and($source)->not->toContain('vendor/autoload');
});
