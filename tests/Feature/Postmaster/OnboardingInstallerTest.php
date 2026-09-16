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
    $request->setLaravelSession(app('session')->driver());

    return app(OnboardingSnippet::class)->generate($request, (string) $user->getKey());
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
