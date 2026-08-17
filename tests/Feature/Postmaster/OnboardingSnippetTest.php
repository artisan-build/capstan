<?php

use App\Enums\OrgRole;
use App\Enums\SpokeLiveness;
use App\Enums\SpokeMapStatus;
use App\Livewire\Postmaster\SpokeMap;
use App\Models\DeviceCode;
use App\Models\Spoke;
use App\Models\User;
use App\Postmaster\OnboardingSnippet;
use App\Support\ServerIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Symfony\Component\Process\Process;

const ONBOARDING_SERVER_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
const OTHER_ONBOARDING_SERVER_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAW';

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'app.name' => 'Capstan',
        'app.url' => 'https://capstan.example',
        'capstan.features.postmaster' => true,
        'capstan.postmaster.server_id' => ONBOARDING_SERVER_ID,
        'capstan.postmaster.signing_key' => 'onboarding-signing-key-material',
    ]);
    Feature::flushCache();
    RateLimiter::clear('postmaster-onboarding:127.0.0.1');
    app()->forgetInstance(ServerIdentity::class);
});

test('the snippet is install specific and contains only an expiring device grant', function (): void {
    $operator = User::factory()->create(['org_role' => OrgRole::Owner]);
    $existingToken = $operator->createToken('capstan-cli')->plainTextToken;
    $existingSecret = explode('|', $existingToken, 2)[1];
    $snippet = app(OnboardingSnippet::class)->generate();
    $device = DeviceCode::query()->sole();

    expect($snippet)
        ->toContain(escapeshellarg(ONBOARDING_SERVER_ID))
        ->toContain(escapeshellarg('https://capstan.example/api/v1/poll'))
        ->toContain(escapeshellarg('https://capstan.example/cli/device?user_code='.$device->user_code))
        ->not->toMatch('/\b\d+\|[A-Za-z0-9]{40,}\b/')
        ->not->toContain($existingToken)
        ->not->toContain($existingSecret)
        ->not->toContain((string) config('app.key'))
        ->not->toContain((string) config('capstan.postmaster.signing_key'))
        ->and(DB::table('personal_access_tokens')->count())->toBe(1)
        ->and($device->expires_at->diffInSeconds(now()))->toBeLessThanOrEqual(DeviceCode::LIFETIME_SECONDS)
        ->and($device->expires_at->isFuture())->toBeTrue();

    preg_match("/^CAPSTAN_DEVICE_CODE='([A-Za-z0-9]+)'$/m", $snippet, $matches);
    expect($matches)->toHaveCount(2)
        ->and($device->device_code_hash)->toBe(DeviceCode::hash($matches[1]));

    config([
        'app.url' => 'https://another.example/base',
        'capstan.postmaster.server_id' => OTHER_ONBOARDING_SERVER_ID,
    ]);
    app()->forgetInstance(ServerIdentity::class);
    $otherSnippet = app(OnboardingSnippet::class)->generate();

    expect($otherSnippet)
        ->not->toBe($snippet)
        ->toContain(escapeshellarg(OTHER_ONBOARDING_SERVER_ID))
        ->toContain(escapeshellarg('https://another.example/base/api/v1/poll'));
});

test('the snippet is valid shell with quoted install values and a minute cron', function (): void {
    config([
        'app.name' => 'Capstan $(touch /tmp/nope) O\'Reilly',
        'app.url' => 'https://example.test/a path/$(not-a-command)',
    ]);
    $snippet = app(OnboardingSnippet::class)->generate();
    $syntax = new Process(['/bin/sh', '-n']);
    $syntax->setInput($snippet);
    $syntax->run();

    $pollScriptPath = tempnam(sys_get_temp_dir(), 'capstan-poll-');
    expect($pollScriptPath)->toBeString();
    $writerNeedle = "\nprintf '%s\\n' '#!/bin/sh'";
    $writerStart = strpos($snippet, $writerNeedle);
    $writerSuffix = ' > "$CAPSTAN_POLL_SCRIPT"';
    expect($writerStart)->toBeInt();
    $writerStart++;
    $writerEnd = strpos($snippet, $writerSuffix, $writerStart);
    expect($writerEnd)->toBeInt();
    $pollScriptWriter = substr($snippet, $writerStart, $writerEnd + strlen($writerSuffix) - $writerStart);
    $pollSyntax = new Process(['/bin/sh', '-c', $pollScriptWriter.' && /bin/sh -n "$CAPSTAN_POLL_SCRIPT"']);
    $pollSyntax->setEnv(['CAPSTAN_POLL_SCRIPT' => $pollScriptPath]);
    $pollSyntax->run();

    expect($syntax->isSuccessful())->toBeTrue($syntax->getErrorOutput())
        ->and($pollSyntax->isSuccessful())->toBeTrue($pollSyntax->getErrorOutput())
        ->and($snippet)->toContain(escapeshellarg((string) config('app.name')))
        ->and($snippet)->toContain(escapeshellarg('https://example.test/a path/$(not-a-command)/api/v1/poll'))
        ->and($snippet)->toMatch('/CAPSTAN_CRON_LINE=.*\* \* \* \* \*/')
        ->and($snippet)->toContain(escapeshellarg(
            'https://example.test/a path/$(not-a-command)/cli/device?user_code='.DeviceCode::query()->sole()->user_code,
        ));

    unlink($pollScriptPath);
});

test('the snippet pins local secret permissions and keeps the bearer out of curl argv', function (): void {
    $snippet = app(OnboardingSnippet::class)->generate();
    $pollScript = onboardingPollScript($snippet);
    $bearerLine = onboardingSnippetLine($pollScript, 'CAPSTAN_RESPONSE=');
    [$curlConfig, $curlArguments] = explode('| curl ', $bearerLine, 2);

    expect($snippet)
        ->toContain("\nset -eu\n")
        ->toContain("\numask 077\n")
        ->toContain('chmod 600 "$CAPSTAN_TOKEN_FILE"')
        ->toContain('chmod 700 "$CAPSTAN_POLL_SCRIPT"')
        ->toContain('chmod 600 "$CAPSTAN_INBOX_FILE"')
        ->toContain('unset CAPSTAN_TOKEN CAPSTAN_TOKEN_RESPONSE CAPSTAN_DEVICE_CODE CAPSTAN_BODY')
        ->and($curlConfig)->toContain('Authorization: Bearer')
        ->and($curlArguments)->toContain('--config -')
        ->not->toContain('Authorization: Bearer');
});

test('a trailing slash in the app url does not duplicate the route separator', function (): void {
    config(['app.url' => 'https://capstan.example/']);

    $snippet = app(OnboardingSnippet::class)->generate();

    expect($snippet)
        ->toContain(escapeshellarg('https://capstan.example/api/v1/poll'))
        ->not->toContain('https://capstan.example//api/v1/poll');
});

test('an empty app url cannot generate a snippet', function (): void {
    config(['app.url' => '']);

    expect(fn (): string => app(OnboardingSnippet::class)->generate())
        ->toThrow(RuntimeException::class);
    expect(DeviceCode::query()->count())->toBe(0);
});

test('dash and zsh send the device exchange as valid JSON to an HTTP endpoint', function (string $shell): void {
    $directory = sys_get_temp_dir().'/capstan-exchange-'.bin2hex(random_bytes(8));
    File::makeDirectory($directory);
    $capture = $directory.'/request.json';
    $router = $directory.'/router.php';
    File::put($router, <<<'PHP'
<?php
file_put_contents((string) getenv('CAPSTAN_CAPTURE'), file_get_contents('php://input'));
header('Content-Type: application/json');
echo '{"token":"fake-token"}';
PHP);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    expect($socket)->toBeResource();
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    expect($address)->toBeString();

    $server = new Process([PHP_BINARY, '-S', $address, $router], $directory, [
        'CAPSTAN_CAPTURE' => $capture,
    ]);

    try {
        $server->start();
        expect($server->waitUntil(
            fn (string $type, string $output): bool => str_contains($output, 'Development Server'),
        ))->toBeTrue();
        config(['app.url' => 'http://'.$address]);
        $snippet = app(OnboardingSnippet::class)->generate();
        $exchange = implode("\n", [
            onboardingSnippetLine($snippet, 'CAPSTAN_TOKEN_URL='),
            onboardingSnippetLine($snippet, 'CAPSTAN_DEVICE_CODE='),
            onboardingSnippetLine($snippet, 'CAPSTAN_BODY='),
            onboardingSnippetLine($snippet, 'CAPSTAN_TOKEN_RESPONSE='),
        ]);
        $process = new Process([$shell, '-c', $exchange]);
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and(File::exists($capture))->toBeTrue();
        $body = json_decode(File::get($capture), true, flags: JSON_THROW_ON_ERROR);
        expect($body)->toHaveKey('device_code')
            ->and($body['device_code'])->toBeString()
            ->and(DeviceCode::hash($body['device_code']))->toBe(DeviceCode::query()->sole()->device_code_hash);
    } finally {
        $server->stop();
        File::deleteDirectory($directory);
    }
})->with([
    'dash' => '/bin/dash',
    'zsh' => '/bin/zsh',
]);

test('installer failures stay inside the snippet subshell', function (string $shell): void {
    $snippet = app(OnboardingSnippet::class)->generate();
    $process = new Process([$shell, '-c', $snippet."\nprintf '%s' caller-survived"], null, [
        'PATH' => '/capstan-test-no-commands',
    ]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toBe('caller-survived');
})->with([
    'dash' => '/bin/dash',
    'zsh' => '/bin/zsh',
]);

test('an immediate poll failure remains successful for cron retry', function (): void {
    $snippet = app(OnboardingSnippet::class)->generate();
    $pollLine = onboardingSnippetLine($snippet, '"$CAPSTAN_POLL_SCRIPT" ||');
    $process = new Process(['/bin/sh', '-c', implode("\n", [
        'set -eu',
        'CAPSTAN_POLL_SCRIPT=/bin/false',
        $pollLine,
        "printf '%s' install-continued",
    ])]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toBe('install-continued');
});

test('the cron installer preserves unrelated jobs and replaces its own tagged line once', function (): void {
    $existing = "MAILTO=ops@example.test\n0 3 * * * /precious-backup.sh\n* * * * * /old-capstan # capstan-postmaster:".ONBOARDING_SERVER_ID."\n";
    $result = runOnboardingCronInstaller(app(OnboardingSnippet::class)->generate(), 'existing', $existing);

    expect($result['successful'])->toBeTrue($result['error'])
        ->and($result['state'])->toContain('MAILTO=ops@example.test', '0 3 * * * /precious-backup.sh')
        ->and(substr_count($result['state'], '# capstan-postmaster:'.ONBOARDING_SERVER_ID))->toBe(1)
        ->and($result['backup'])->toBe($existing)
        ->and($result['write_calls'])->toBe(1);
});

test('the cron installer treats an explicit no-crontab response as an empty crontab', function (): void {
    $result = runOnboardingCronInstaller(app(OnboardingSnippet::class)->generate(), 'none');

    expect($result['successful'])->toBeTrue($result['error'])
        ->and($result['state'])->toContain('* * * * *')
        ->and(substr_count($result['state'], '# capstan-postmaster:'.ONBOARDING_SERVER_ID))->toBe(1)
        ->and($result['backup'])->toBe('')
        ->and($result['write_calls'])->toBe(1);
});

test('the cron installer refuses an unrelated read failure without rewriting', function (): void {
    $existing = "MAILTO=ops@example.test\n0 3 * * * /precious-backup.sh\n";
    $result = runOnboardingCronInstaller(app(OnboardingSnippet::class)->generate(), 'failure', $existing);

    expect($result['successful'])->toBeFalse()
        ->and($result['state'])->toBe($existing)
        ->and($result['backup'])->toBeNull()
        ->and($result['write_calls'])->toBe(0);
});

test('owners and admins can generate the onboarding panel on demand', function (OrgRole $role): void {
    $operator = User::factory()->create(['org_role' => $role]);

    $component = Livewire::actingAs($operator)->test(SpokeMap::class);

    $component->assertOk()
        ->assertViewHas('canOnboard', true)
        ->assertSeeHtml('data-onboarding-panel')
        ->assertDontSeeHtml('data-onboarding-snippet')
        ->assertSet('onboardingSnippet', null)
        ->call('generateOnboardingSnippet')
        ->assertSeeHtml('data-onboarding-snippet')
        ->assertSeeHtml('data-onboarding-expires-at');
    expect($component->get('onboardingSnippet'))->toBeString()
        ->and($component->get('onboardingExpiresAt'))->toBeGreaterThan(now()->timestamp)
        ->and(DeviceCode::query()->count())->toBe(1)
        ->and(DB::table('personal_access_tokens')->count())->toBe(0);
})->with([
    'owner' => OrgRole::Owner,
    'admin' => OrgRole::Admin,
]);

test('members cannot retrieve or generate a snippet', function (): void {
    $member = User::factory()->create(['org_role' => OrgRole::Member]);

    $component = Livewire::actingAs($member)->test(SpokeMap::class);

    $component->assertOk()
        ->assertViewHas('canOnboard', false)
        ->assertDontSeeHtml('data-onboarding-panel')
        ->assertSet('onboardingSnippet', null)
        ->call('generateOnboardingSnippet')
        ->assertForbidden();
    expect(DeviceCode::query()->count())->toBe(0);
});

test('onboarding requires authentication', function (): void {
    $this->get(route('postmaster.map'))
        ->assertRedirect(route('login'));

    expect(DeviceCode::query()->count())->toBe(0);
});

test('a disabled feature rejects initial and existing component requests without issuing codes', function (): void {
    $owner = User::factory()->create(['org_role' => OrgRole::Owner]);
    config(['capstan.features.postmaster' => false]);
    Feature::flushCache();

    $this->actingAs($owner)->get(route('postmaster.map'))->assertNotFound();
    expect(DeviceCode::query()->count())->toBe(0);

    config(['capstan.features.postmaster' => true]);
    Feature::flushCache();
    $component = Livewire::actingAs($owner)->test(SpokeMap::class);
    expect(DeviceCode::query()->count())->toBe(0);
    $component->call('generateOnboardingSnippet');
    expect(DeviceCode::query()->count())->toBe(1);

    config(['capstan.features.postmaster' => false]);
    Feature::flushCache();
    $component->call('generateOnboardingSnippet')->assertNotFound();
    expect(DeviceCode::query()->count())->toBe(1);
});

test('a role change is enforced before regenerating a snippet', function (): void {
    $admin = User::factory()->create(['org_role' => OrgRole::Admin]);
    $component = Livewire::actingAs($admin)->test(SpokeMap::class);
    $admin->forceFill(['org_role' => OrgRole::Member])->save();

    $component->call('generateOnboardingSnippet')->assertForbidden();

    expect(DeviceCode::query()->count())->toBe(0);
});

test('demoting an admin clears a live snippet from the next snapshot', function (): void {
    $admin = User::factory()->create(['org_role' => OrgRole::Admin]);
    $component = Livewire::actingAs($admin)
        ->test(SpokeMap::class)
        ->call('generateOnboardingSnippet');

    expect($component->get('onboardingSnippet'))->toBeString()
        ->and($component->get('onboardingExpiresAt'))->toBeInt();

    $admin->forceFill(['org_role' => OrgRole::Member])->save();

    $component->call('$refresh')
        ->assertSet('onboardingSnippet', null)
        ->assertSet('onboardingExpiresAt', null);
});

test('onboarding generation is limited to fifteen attempts per minute per ip', function (): void {
    $owner = User::factory()->create(['org_role' => OrgRole::Owner]);
    $component = Livewire::actingAs($owner)->test(SpokeMap::class);

    foreach (range(1, 15) as $attempt) {
        $component->call('generateOnboardingSnippet')->assertOk();
    }

    $component->call('generateOnboardingSnippet')->assertStatus(429);
    expect(DeviceCode::query()->count())->toBe(15);
});

test('the first poll is pending and the first passing probe turns the same spoke green', function (): void {
    $owner = User::factory()->create(['org_role' => OrgRole::Owner]);
    $token = $owner->createToken('capstan-cli')->plainTextToken;
    $first = $this->withToken($token)->postJson(route('api.postmaster.poll'), [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk();
    $spoke = Spoke::query()->sole();

    expect($spoke->probe_status)->toBe(SpokeLiveness::Unknown)
        ->and(Spoke::query()->count())->toBe(1);
    $pending = Livewire::actingAs($owner)->test(SpokeMap::class);
    expect($pending->viewData('spokes')->sole()['status'])->toBe(SpokeMapStatus::Pending);
    $pending->assertSeeHtml('data-status="pending"');

    $challenge = $first->json('probe_challenge');
    $this->withToken($token)->postJson(route('api.postmaster.poll'), [
        'presence' => ['ready_inboxes' => []],
        'probe_response' => [
            'probe_id' => $challenge['probe_id'],
            'digest' => hash('sha256', $challenge['nonce']),
        ],
    ])->assertOk();

    expect(Spoke::query()->count())->toBe(1)
        ->and($spoke->refresh()->probe_status)->toBe(SpokeLiveness::Green);
    $green = Livewire::actingAs($owner)->test(SpokeMap::class);
    expect($green->viewData('spokes')->sole()['status'])->toBe(SpokeMapStatus::Green);
    $green->assertSeeHtml('data-status="green"');
});

function onboardingSnippetLine(string $snippet, string $prefix): string
{
    $line = collect(explode("\n", $snippet))
        ->first(fn (string $line): bool => str_starts_with(ltrim($line), $prefix));

    expect($line)->toBeString();

    return ltrim($line);
}

function onboardingSnippetSection(string $snippet, string $start, string $end): string
{
    $startAt = strpos($snippet, $start);
    $endAt = strpos($snippet, $end);
    expect($startAt)->toBeInt()
        ->and($endAt)->toBeInt();

    return substr($snippet, $startAt, $endAt + strlen($end) - $startAt);
}

function onboardingPollScript(string $snippet): string
{
    $path = tempnam(sys_get_temp_dir(), 'capstan-poll-');
    expect($path)->toBeString();
    $writerNeedle = "\nprintf '%s\\n' '#!/bin/sh'";
    $writerStart = strpos($snippet, $writerNeedle);
    $writerSuffix = ' > "$CAPSTAN_POLL_SCRIPT"';
    expect($writerStart)->toBeInt();
    $writerStart++;
    $writerEnd = strpos($snippet, $writerSuffix, $writerStart);
    expect($writerEnd)->toBeInt();
    $writer = substr($snippet, $writerStart, $writerEnd + strlen($writerSuffix) - $writerStart);
    $process = new Process(['/bin/sh', '-c', $writer], null, ['CAPSTAN_POLL_SCRIPT' => $path]);
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    $pollScript = File::get($path);
    unlink($path);

    return $pollScript;
}

/**
 * @return array{successful: bool, error: string, state: string, backup: string|null, write_calls: int}
 */
function runOnboardingCronInstaller(string $snippet, string $mode, ?string $initial = null): array
{
    $directory = sys_get_temp_dir().'/capstan-cron-'.bin2hex(random_bytes(8));
    $bin = $directory.'/bin';
    $home = $directory.'/home';
    $state = $directory.'/crontab';
    $calls = $directory.'/write-calls';
    File::makeDirectory($bin, 0700, true);
    File::makeDirectory($home, 0700, true);

    if ($initial !== null) {
        File::put($state, $initial);
    }

    $shim = $bin.'/crontab';
    File::put($shim, <<<'SH'
#!/bin/sh
set -eu
if [ "${1:-}" = "-l" ]; then
    case "$CRONTAB_MODE" in
        existing) [ ! -e "$CRONTAB_STATE" ] || cat "$CRONTAB_STATE"; exit 0 ;;
        none) printf '%s\n' 'no crontab for test-user' >&2; exit 1 ;;
        failure) printf '%s\n' 'Operation not permitted' >&2; exit 1 ;;
    esac
fi
printf '%s\n' "$*" >> "$CRONTAB_CALLS"
if [ "$1" = "-" ]; then
    cat > "$CRONTAB_STATE"
else
    cp "$1" "$CRONTAB_STATE"
fi
SH);
    chmod($shim, 0700);

    $script = implode("\n", [
        'set -eu',
        onboardingSnippetLine($snippet, 'CAPSTAN_SERVER_ID='),
        onboardingSnippetLine($snippet, 'CAPSTAN_HOME='),
        onboardingSnippetLine($snippet, 'CAPSTAN_CRON_TAG='),
        onboardingSnippetLine($snippet, 'CAPSTAN_CRON_LINE='),
        onboardingSnippetLine($snippet, 'CAPSTAN_CRON_READ='),
        onboardingSnippetLine($snippet, 'CAPSTAN_CRON_ERR='),
        onboardingSnippetLine($snippet, 'CAPSTAN_CRON_BACKUP='),
        onboardingSnippetLine($snippet, 'CAPSTAN_CRON_NEW='),
        'umask 077',
        'mkdir -p "$CAPSTAN_HOME"',
        onboardingSnippetSection($snippet, '# CAPSTAN_CRON_INSTALL_BEGIN', '# CAPSTAN_CRON_INSTALL_END'),
    ]);
    $process = new Process(['/bin/sh', '-c', $script], null, [
        'CRONTAB_CALLS' => $calls,
        'CRONTAB_MODE' => $mode,
        'CRONTAB_STATE' => $state,
        'HOME' => $home,
        'PATH' => $bin.':'.(string) getenv('PATH'),
    ]);
    $process->run();
    $backup = $home.'/.config/capstan/'.ONBOARDING_SERVER_ID.'/crontab.before-capstan';
    $result = [
        'successful' => $process->isSuccessful(),
        'error' => $process->getErrorOutput(),
        'state' => File::exists($state) ? File::get($state) : '',
        'backup' => File::exists($backup) ? File::get($backup) : null,
        'write_calls' => File::exists($calls)
            ? File::lines($calls)->filter(fn (string $line): bool => trim($line) !== '')->count()
            : 0,
    ];
    File::deleteDirectory($directory);

    return $result;
}
