<?php

use App\Auth\CapstanCredentialDeclaration;
use App\Postmaster\OnboardingSnippet;
use App\Support\ServerIdentity;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Support\Facades\File;
use Laravel\Pennant\Feature;
use Symfony\Component\Process\Process;

const INSTALLER_SERVER_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('i', 32)),
        'app.name' => 'Capstan',
        'app.url' => 'https://capstan.example',
        'capstan.features.postmaster' => true,
        'capstan.postmaster.server_id' => INSTALLER_SERVER_ID,
    ]);
    Feature::flushCache();
    app()->forgetInstance(ServerIdentity::class);
});

function installerSnippet(object $test): string
{
    $user = capstanUser();
    $test->actingAs($user);
    $request = request();
    $request->setUserResolver(static fn (): User => $user);
    $request->setLaravelSession(resolve('session')->driver());

    return resolve(OnboardingSnippet::class)->generate($request, (string) $user->getKey());
}

function installerLine(string $snippet, string $prefix): string
{
    $line = collect(explode("\n", $snippet))
        ->first(fn (string $line): bool => str_starts_with(ltrim($line), $prefix));
    expect($line)->toBeString();

    return ltrim($line);
}

test('the generated installer is valid shell and protects local credentials and transport', function (): void {
    $existing = capstanBoundBearer(capstanUser(), CapstanCredentialDeclaration::POSTMASTER_POLL);
    $snippet = installerSnippet($this);
    $syntax = new Process(['/bin/sh', '-n']);
    $syntax->setInput($snippet);
    $syntax->run();

    expect($syntax->isSuccessful())->toBeTrue($syntax->getErrorOutput())
        ->and($snippet)->toContain("\numask 077\n")
        ->toContain('chmod 700 "$CAPSTAN_HOME"')
        ->toContain('chmod 600 "$CAPSTAN_TOKEN_FILE"')
        ->toContain('chmod 600 "$CAPSTAN_ACTOR_FILE"')
        ->toContain('chmod 700 "$CAPSTAN_POLL_SCRIPT"')
        ->toContain("'https://capstan.example/bfc/device/token'")
        ->toContain('scutil --get ComputerName 2>/dev/null')
        ->toContain('hostname -s 2>/dev/null || hostname 2>/dev/null')
        ->toContain('Authorization: Bearer', 'X-Capstan-Actor-ID', '| "$CAPSTAN_CURL" --config -')
        ->not->toContain('| curl ')
        ->not->toContain('| php ')
        ->not->toContain((string) config('app.key'))
        ->not->toContain($existing['token']);
});

test('a truncated installer fails parsing before it can create local files', function (): void {
    $snippet = installerSnippet($this);
    $truncated = (string) preg_replace('/\n\)\n\z/', "\n", $snippet);
    $directory = sys_get_temp_dir().'/capstan-truncated-'.bin2hex(random_bytes(6));
    File::makeDirectory($directory, 0700, true);
    $home = $directory.'/home';
    File::makeDirectory($home, 0700, true);

    $syntax = new Process(['/bin/sh', '-n']);
    $syntax->setInput($truncated);
    $syntax->run();

    $execution = new Process(['/bin/sh', '-c', $truncated], null, [
        'HOME' => $home,
        'PATH' => dirname(PHP_BINARY).':/usr/bin:/bin',
    ]);
    $execution->run();

    try {
        expect($truncated)->not->toBe($snippet)
            ->and($syntax->isSuccessful())->toBeFalse()
            ->and($execution->isSuccessful())->toBeFalse()
            ->and(File::exists($home.'/.config/capstan'))->toBeFalse();
    } finally {
        File::deleteDirectory($directory);
    }
});

test('device polling sleeps for the greatest current returned and Retry-After interval', function (int $current, int $returned, int $retryAfter, int $expected): void {
    $snippet = installerSnippet($this);
    $directory = sys_get_temp_dir().'/capstan-interval-'.bin2hex(random_bytes(6));
    File::makeDirectory($directory, 0700, true);
    $headers = $directory.'/headers';
    File::put($headers, "HTTP/1.1 400 Bad Request\r\nRetry-After: {$retryAfter}\r\n\r\n");
    $script = implode("\n", [
        'set -eu',
        'CAPSTAN_PHP='.escapeshellarg(PHP_BINARY),
        'CAPSTAN_INTERVAL='.$current,
        'CAPSTAN_TOKEN_RESPONSE='.escapeshellarg(json_encode(['interval' => $returned], JSON_THROW_ON_ERROR)),
        'CAPSTAN_TOKEN_HEADERS='.escapeshellarg($headers),
        installerLine($snippet, 'CAPSTAN_RETURNED_INTERVAL='),
        installerLine($snippet, 'CAPSTAN_RETRY_AFTER='),
        installerLine($snippet, '[ -z "$CAPSTAN_RETURNED_INTERVAL" ]'),
        installerLine($snippet, '[ "$CAPSTAN_RETRY_AFTER" -le'),
        'printf \'%s\' "$CAPSTAN_INTERVAL"',
    ]);
    $process = Process::fromShellCommandline($script);
    $process->run();
    File::deleteDirectory($directory);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toBe((string) $expected);
})->with([
    'current wins' => [9, 7, 3, 9],
    'json interval wins' => [5, 7, 3, 7],
    'Retry-After wins' => [5, 7, 11, 11],
]);

test('the generated installer pins relative executable paths before cron runs from another directory', function (): void {
    $snippet = installerSnippet($this);
    $user = User::query()->sole();
    $directory = sys_get_temp_dir().'/capstan-installer-'.bin2hex(random_bytes(6));
    $bin = $directory.'/bin';
    $installDirectory = $directory.'/install work';
    $cronDirectory = $directory.'/cron work';
    $relativeTools = 'relative tools';
    $tools = $installDirectory.'/'.$relativeTools;
    $home = $directory.'/home';
    $crontab = $directory.'/crontab';
    $pollPayload = $directory.'/poll-payload.json';
    File::makeDirectory($bin, 0700, true);
    File::makeDirectory($tools, 0700, true);
    $absoluteTools = (string) realpath($tools);
    File::makeDirectory($cronDirectory, 0700, true);
    File::makeDirectory($home, 0700, true);
    symlink(PHP_BINARY, $tools.'/php');

    File::put($bin.'/uname', "#!/bin/sh\nprintf '%s\\n' Darwin\n");
    File::put($bin.'/scutil', "#!/bin/sh\nprintf '%s\\n' \"Ed's Test Mac\"\n");
    File::put($bin.'/hostname', "#!/bin/sh\nprintf '%s\\n' 'Fresh Test Machine'\n");
    File::put($bin.'/open', "#!/bin/sh\nexit 0\n");
    File::put($bin.'/crontab', <<<'SH'
#!/bin/sh
set -eu
if [ "${1:-}" = "-l" ]; then
    printf '%s\n' 'no crontab for test-user' >&2
    exit 1
fi
cp "$1" "$CAPSTAN_TEST_CRONTAB"
SH);
    File::put($tools.'/curl', str_replace('__POLL_PAYLOAD__', escapeshellarg($pollPayload), <<<'SH'
#!/bin/sh
set -eu
if [ "${1:-}" = '--version' ]; then
    printf '%s\n' 'curl test shim'
    exit 0
fi
headers=''
previous=''
for argument in "$@"; do
    if [ "$previous" = '--dump-header' ]; then headers="$argument"; fi
    if [ "$previous" = '--data' ] && [ "$argument" != '@-' ]; then printf '%s' "$argument" > __POLL_PAYLOAD__; fi
    previous="$argument"
done
cat >/dev/null || true
if [ -n "$headers" ]; then
    printf 'HTTP/1.1 200 OK\r\n\r\n' > "$headers"
    printf '%s' '{"access_token":"approved-test-bearer"}'
else
    printf '%s' '{"probe_challenge":{"probe_id":"01ARZ3NDEKTSV4RRFFQ69G5FAA","nonce":"installer-nonce"}}'
fi
SH));
    foreach (['uname', 'scutil', 'hostname', 'open', 'crontab'] as $command) {
        chmod($bin.'/'.$command, 0700);
    }
    chmod($tools.'/curl', 0700);

    $process = new Process(['/bin/sh', '-c', $snippet], $installDirectory, [
        'CAPSTAN_TEST_CRONTAB' => $crontab,
        'HOME' => $home,
        'PATH' => $relativeTools.':'.$bin.':/usr/bin:/bin',
    ]);

    try {
        $process->run();
        $install = $home.'/.config/capstan/'.INSTALLER_SERVER_ID;
        $initialPayload = json_decode(File::get($pollPayload), true, flags: JSON_THROW_ON_ERROR);
        $cronProcess = new Process([
            '/usr/bin/env',
            '-i',
            'HOME='.$home,
            'PATH=/usr/bin:/bin',
            'sh',
            $install.'/poll.sh',
        ], $cronDirectory);
        $cronProcess->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and(File::get($install.'/token'))->toBe('approved-test-bearer')
            ->and(File::get($install.'/actor-id'))->toBe((string) $user->getKey())
            ->and(fileperms($install) & 0777)->toBe(0700)
            ->and(fileperms($install.'/token') & 0777)->toBe(0600)
            ->and(fileperms($install.'/actor-id') & 0777)->toBe(0600)
            ->and(fileperms($install.'/poll.sh') & 0777)->toBe(0700)
            ->and(File::get($install.'/poll.sh'))->toContain('CAPSTAN_PHP='.escapeshellarg($absoluteTools.'/php'))
            ->and(File::get($install.'/poll.sh'))->toContain('CAPSTAN_CURL='.escapeshellarg($absoluteTools.'/curl'))
            ->and(File::get($crontab))->toContain('# capstan-postmaster:'.INSTALLER_SERVER_ID)
            ->and($initialPayload['presence'])->toBe([
                'ready_inboxes' => [],
                'device_name' => "Ed's Test Mac",
            ])
            ->and($cronProcess->isSuccessful())->toBeTrue($cronProcess->getErrorOutput())
            ->and(json_decode(File::get($pollPayload), true, flags: JSON_THROW_ON_ERROR)['probe_response'])->toBe([
                'probe_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAA',
                'digest' => hash('sha256', 'installer-nonce'),
            ])
            ->and(json_decode(File::get($install.'/probe-response.json'), true, flags: JSON_THROW_ON_ERROR))->toBe([
                'probe_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAA',
                'digest' => hash('sha256', 'installer-nonce'),
            ]);
    } finally {
        File::deleteDirectory($directory);
    }
});

test('the generated installer refuses a php shell function even beside a same-named executable', function (): void {
    $snippet = "php() { :; }\n".installerSnippet($this);
    $directory = sys_get_temp_dir().'/capstan-installer-function-'.bin2hex(random_bytes(6));
    $home = $directory.'/home';
    $workingDirectory = $directory.'/work';
    File::makeDirectory($home, 0700, true);
    File::makeDirectory($workingDirectory, 0700, true);
    File::put($workingDirectory.'/php', "#!/bin/sh\nexit 0\n");
    chmod($workingDirectory.'/php', 0700);
    $process = new Process(['/bin/sh', '-c', $snippet], $workingDirectory, [
        'HOME' => $home,
        'PATH' => dirname(PHP_BINARY).':/usr/bin:/bin',
    ]);

    try {
        $process->run();

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('php must resolve to an executable file.')
            ->and(File::exists($home.'/.config/capstan'))->toBeFalse();
    } finally {
        File::deleteDirectory($directory);
    }
});

test('the generated installer stops on terminal device outcomes', function (string $error): void {
    $snippet = installerSnippet($this);
    $case = installerLine($snippet, 'case "$CAPSTAN_ERROR"');
    $process = Process::fromShellCommandline(implode("\n", [
        'set -eu',
        'CAPSTAN_ERROR='.escapeshellarg($error),
        $case,
        "printf '%s' continued",
    ]));
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getOutput())->not->toContain('continued')
        ->and($process->getErrorOutput())->toContain('Authorization failed: '.$error);
})->with([
    'denial' => 'access_denied',
    'expiry' => 'expired_token',
    'revoked or consumed grant' => 'invalid_grant',
]);
