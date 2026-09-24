<?php

use App\Auth\CapstanCredentialDeclaration;
use App\Postmaster\OnboardingSnippet;
use App\Support\ServerIdentity;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Http\Request;
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
    $request = Request::create('/postmaster', 'GET');
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
        ->toContain('Authorization: Bearer', 'X-Capstan-Actor-ID', '| curl --config -')
        ->not->toContain((string) config('app.key'))
        ->not->toContain($existing['token']);
});

test('device polling sleeps for the greatest current returned and Retry-After interval', function (int $current, int $returned, int $retryAfter, int $expected): void {
    $snippet = installerSnippet($this);
    $directory = sys_get_temp_dir().'/capstan-interval-'.bin2hex(random_bytes(6));
    File::makeDirectory($directory, 0700, true);
    $headers = $directory.'/headers';
    File::put($headers, "HTTP/1.1 400 Bad Request\r\nRetry-After: {$retryAfter}\r\n\r\n");
    $script = implode("\n", [
        'set -eu',
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

test('the generated installer completes approval, installs fake cron, and performs its first probe poll', function (): void {
    $snippet = installerSnippet($this);
    $user = User::query()->sole();
    $directory = sys_get_temp_dir().'/capstan-installer-'.bin2hex(random_bytes(6));
    $bin = $directory.'/bin';
    $home = $directory.'/home';
    $crontab = $directory.'/crontab';
    File::makeDirectory($bin, 0700, true);
    File::makeDirectory($home, 0700, true);

    File::put($bin.'/uname', "#!/bin/sh\nprintf '%s\\n' Test\n");
    File::put($bin.'/crontab', <<<'SH'
#!/bin/sh
set -eu
if [ "${1:-}" = "-l" ]; then
    printf '%s\n' 'no crontab for test-user' >&2
    exit 1
fi
cp "$1" "$CAPSTAN_TEST_CRONTAB"
SH);
    File::put($bin.'/curl', <<<'SH'
#!/bin/sh
set -eu
headers=''
previous=''
for argument in "$@"; do
    if [ "$previous" = '--dump-header' ]; then headers="$argument"; fi
    previous="$argument"
done
cat >/dev/null || true
if [ -n "$headers" ]; then
    printf 'HTTP/1.1 200 OK\r\n\r\n' > "$headers"
    printf '%s' '{"access_token":"approved-test-bearer"}'
else
    printf '%s' '{"probe_challenge":{"probe_id":"01ARZ3NDEKTSV4RRFFQ69G5FAA","nonce":"installer-nonce"}}'
fi
SH);
    foreach (['uname', 'crontab', 'curl'] as $command) {
        chmod($bin.'/'.$command, 0700);
    }

    $process = new Process(['/bin/sh', '-c', $snippet], null, [
        'CAPSTAN_TEST_CRONTAB' => $crontab,
        'HOME' => $home,
        'PATH' => $bin.':'.dirname(PHP_BINARY).':/usr/bin:/bin',
    ]);

    try {
        $process->run();
        $install = $home.'/.config/capstan/'.INSTALLER_SERVER_ID;

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and(File::get($install.'/token'))->toBe('approved-test-bearer')
            ->and(File::get($install.'/actor-id'))->toBe((string) $user->getKey())
            ->and(fileperms($install) & 0777)->toBe(0700)
            ->and(fileperms($install.'/token') & 0777)->toBe(0600)
            ->and(fileperms($install.'/actor-id') & 0777)->toBe(0600)
            ->and(fileperms($install.'/poll.sh') & 0777)->toBe(0700)
            ->and(File::get($crontab))->toContain('# capstan-postmaster:'.INSTALLER_SERVER_ID)
            ->and(json_decode(File::get($install.'/probe-response.json'), true, flags: JSON_THROW_ON_ERROR))->toBe([
                'probe_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAA',
                'digest' => hash('sha256', 'installer-nonce'),
            ]);
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
