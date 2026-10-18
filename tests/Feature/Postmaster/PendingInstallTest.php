<?php

use App\Postmaster\PendingInstallLink;
use App\Postmaster\PendingInstallLinks;
use App\Postmaster\ProtectInstallCapabilityTelemetry;
use App\Support\ServerIdentity;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Laravel\Nightwatch\Clock;
use Laravel\Nightwatch\Contracts\Ingest as NightwatchIngest;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Location;
use Laravel\Nightwatch\SensorManager;
use Laravel\Nightwatch\State\RequestState;
use Laravel\Nightwatch\Support\Uuid;
use Laravel\Nightwatch\UserProvider;
use Laravel\Pennant\Feature;
use Symfony\Component\Process\Process;

final class PendingInstallRecordingIngest implements NightwatchIngest
{
    /** @var list<array<mixed>> */
    public array $records = [];

    public function write(array $record): void
    {
        $this->records[] = $record;
    }

    public function writeNow(array $record): void
    {
        $this->write($record);
    }

    public function ping(): void {}

    public function shouldDigest(bool $bool = true): void {}

    public function shouldDigestWhenBufferIsFull(bool $bool = true): void {}

    public function digest(): void {}

    public function flush(): void {}
}

const PENDING_INSTALL_SERVER_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
        'app.name' => 'Capstan',
        'app.url' => 'https://capstan.example',
        'capstan.features.postmaster' => true,
        'capstan.postmaster.server_id' => PENDING_INSTALL_SERVER_ID,
    ]);
    Feature::flushCache();
    app()->forgetInstance(ServerIdentity::class);
    request()->setLaravelSession(resolve('session')->driver());
});

function pendingInstallFor(object $test, User $user): PendingInstallLink
{
    $test->actingAs($user);
    $request = request();
    $request->setUserResolver(static fn (): User => $user);
    $request->setLaravelSession(resolve('session')->driver());

    return resolve(PendingInstallLinks::class)->issue($request, (string) $user->getKey());
}

function pendingInstallToken(PendingInstallLink $install): string
{
    preg_match("/curl -sSL --fail-with-body '([^']+)' \\| sh/", $install->command, $match);
    expect($match)->toHaveCount(2);

    return (string) str($match[1])->afterLast('/');
}

function pendingInstallNightwatch(PendingInstallRecordingIngest $ingest): Core
{
    $clock = new Clock;
    $timestamp = microtime(true);
    $user = new UserProvider(
        static fn (callable $callback): mixed => $callback(resolve('auth')),
        static fn (): null => null,
        static fn (): Closure => static function (Throwable $exception, bool $handled): void {},
    );
    $state = new RequestState(
        timestamp: $timestamp,
        trace: 'test-trace',
        id: 'test-request',
        deploy: 'test-deploy',
        server: 'test-server',
        currentExecutionStageStartedAtMicrotime: $timestamp,
        user: $user,
    );

    return new Core(
        ingest: $ingest,
        sensor: new SensorManager(
            executionState: $state,
            clock: $clock,
            location: new Location(base_path(), public_path()),
            captureExceptionSourceCode: false,
            captureRequestPayload: false,
            redactPayloadFields: ['_token', 'password', 'password_confirmation'],
            redactHeaders: ['Authorization', 'Cookie'],
            container: app(),
        ),
        executionState: $state,
        clock: $clock,
        uuid: new Uuid(static fn (): string => 'test-uuid'),
        config: [
            'enabled' => true,
            'sampling' => [
                'requests' => 1.0,
                'commands' => 1.0,
                'exceptions' => 1.0,
                'scheduled_tasks' => 1.0,
            ],
            'filtering' => [
                'ignore_cache_events' => false,
                'ignore_mail' => false,
                'ignore_notifications' => false,
                'ignore_outgoing_requests' => false,
                'ignore_queries' => false,
            ],
        ],
    );
}

test('the unauthenticated install endpoint serves the exact script once without caching it', function (): void {
    $install = pendingInstallFor($this, capstanUser());
    $token = pendingInstallToken($install);
    $path = parse_url((string) str($install->command)->between("'", "'"), PHP_URL_PATH);
    $this->flushSession();
    $this->app['auth']->forgetGuards();

    expect($path)->toBeString();

    $first = $this->get($path)
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

    expect($first->content())->toBe($install->script)
        ->and($first->headers->get('Cache-Control'))->toContain('no-store')
        ->and(DB::table('postmaster_installs')->sole()->used_at)->not->toBeNull();

    $failure = 'echo "This install link was already used or has expired. Generate a new one." >&2; exit 1'."\n";
    $this->get($path)
        ->assertGone()
        ->assertContent($failure);

    expect(DB::table('postmaster_installs')->sole()->token_hash)->toBe(hash('sha256', $token));
});

test('the map dispatches the command and full script without persisting the capability in its snapshot', function (): void {
    $operator = capstanUser();
    $page = $this->actingAsVersioned($operator)->get(route('postmaster.map'))->assertOk();
    $snapshot = htmlspecialchars_decode(
        (string) str($page->getContent())->betweenFirst('wire:snapshot="', '"'),
        ENT_QUOTES | ENT_SUBSTITUTE,
    );

    $response = $this->withHeader('X-Livewire', '1')
        ->postJson(route('default-livewire.update'), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [[
                    'path' => '',
                    'method' => 'generateOnboardingSnippet',
                    'params' => [],
                ]],
            ]],
        ])
        ->assertOk();

    $dispatch = collect($response->json('components.0.effects.dispatches'))
        ->firstWhere('name', 'postmaster-installer-generated');

    expect($dispatch)->toBeArray()
        ->and($dispatch['params']['command'])->toStartWith("curl -sSL --fail-with-body 'https://capstan.example/postmaster/install/")
        ->toEndWith("' | sh")
        ->and($dispatch['params']['script'])->toStartWith("#!/bin/sh\n(\n")
        ->toContain("CAPSTAN_ACTOR_ID='".$operator->getKey()."'")
        ->and($dispatch['params']['expiresAt'])->toBeInt()
        ->and($response->json('components.0.snapshot'))->not->toContain('/postmaster/install/')
        ->and(DB::table('postmaster_installs')->count())->toBe(1);
});

test('the copied command reports a consumed link and exits unsuccessfully', function (): void {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    expect($socket)->not->toBeFalse($errorMessage);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    expect($address)->toBeString();
    $port = (int) str($address)->afterLast(':')->toString();
    $directory = storage_path('framework/testing/postmaster-pipeline-'.bin2hex(random_bytes(6)));
    mkdir($directory, 0700, true);
    $router = $directory.'/router.php';
    file_put_contents($router, <<<'PHP'
<?php
http_response_code(410);
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
echo 'echo "This install link was already used or has expired. Generate a new one." >&2; exit 1'."\n";
PHP);
    $server = new Process([PHP_BINARY, '-S', "127.0.0.1:{$port}", $router], base_path());
    $server->disableOutput();
    $server->start();

    try {
        $deadline = microtime(true) + 5;
        $serverReady = false;

        do {
            $connection = @fsockopen('127.0.0.1', $port, $connectErrorCode, $connectErrorMessage, 0.05);

            if (is_resource($connection)) {
                fclose($connection);
                $serverReady = true;
                break;
            }

            Sleep::usleep(10_000);
        } while (microtime(true) < $deadline);

        expect($serverReady)->toBeTrue();
        config(['app.url' => "http://127.0.0.1:{$port}"]);
        $install = pendingInstallFor($this, capstanUser());
        $token = pendingInstallToken($install);
        expect(resolve(PendingInstallLinks::class)->consume($token))->toBe($install->script)
            ->and(resolve(PendingInstallLinks::class)->consume($token))->toBeNull();

        $pipeline = Process::fromShellCommandline($install->command, base_path(), null, null, 10);
        $pipeline->run();
        $output = $pipeline->getOutput().$pipeline->getErrorOutput();

        expect($pipeline->isSuccessful())->toBeFalse()
            ->and($output)->toContain('This install link was already used or has expired. Generate a new one.');
    } finally {
        $server->stop(1);
        @unlink($router);
        @rmdir($directory);
    }
});

test('install capabilities are stored only as hashes with the device authorization expiry', function (): void {
    $install = pendingInstallFor($this, capstanUser());
    $token = pendingInstallToken($install);
    $row = DB::table('postmaster_installs')->sole();
    $authorization = DB::table('credential_authorizations')->sole();

    expect(json_encode((array) $row, JSON_THROW_ON_ERROR))->not->toContain($token)
        ->and($row->token_hash)->toBe(hash('sha256', $token))
        ->and(strtotime((string) $row->expires_at))->toBe(strtotime((string) $authorization->expires_at))
        ->and($install->expiresAt->getTimestamp())->toBe(strtotime((string) $authorization->expires_at));
});

test('expired unknown and replaced install capabilities return the same shell safe failure', function (): void {
    $operator = capstanUser();
    $first = pendingInstallFor($this, $operator);
    $firstToken = pendingInstallToken($first);
    $second = pendingInstallFor($this, $operator);
    $secondToken = pendingInstallToken($second);
    $failure = 'echo "This install link was already used or has expired. Generate a new one." >&2; exit 1'."\n";

    $this->get(route('postmaster.install', ['token' => $firstToken]))
        ->assertGone()
        ->assertContent($failure);

    DB::table('postmaster_installs')
        ->where('token_hash', hash('sha256', $secondToken))
        ->update(['expires_at' => now()->subSecond()]);

    $this->get(route('postmaster.install', ['token' => $secondToken]))
        ->assertGone()
        ->assertContent($failure);
    $this->get(route('postmaster.install', ['token' => 'unknown-capability']))
        ->assertGone()
        ->assertContent($failure);
});

test('the install route emits successful and throttled Nightwatch records without its capability', function (): void {
    $install = pendingInstallFor($this, capstanUser());
    $sentinel = pendingInstallToken($install);
    $ingest = new PendingInstallRecordingIngest;
    $nightwatch = pendingInstallNightwatch($ingest);
    app()->instance(Core::class, $nightwatch);
    Event::listen(RequestHandled::class, static function (RequestHandled $event) use ($nightwatch): void {
        $nightwatch->request($event->request, $event->response);
    });

    $route = resolve('router')->getRoutes()->getByName('postmaster.install');
    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain(
            ProtectInstallCapabilityTelemetry::class,
            'throttle:postmaster-install',
        )
        ->and(array_search(ProtectInstallCapabilityTelemetry::class, $route->gatherMiddleware(), true))
        ->toBeLessThan(array_search('throttle:postmaster-install', $route->gatherMiddleware(), true));

    $this->get(route('postmaster.install', ['token' => $sentinel]))->assertOk();

    foreach (range(1, 14) as $attempt) {
        $this->get(route('postmaster.install', ['token' => $sentinel]))->assertGone();
    }

    $this->get(route('postmaster.install', ['token' => $sentinel]))->assertTooManyRequests();
    $success = collect($ingest->records)->firstWhere('status_code', 200);
    $throttled = collect($ingest->records)->firstWhere('status_code', 429);
    $recordedOutput = json_encode([$success, $throttled], JSON_THROW_ON_ERROR);

    expect($nightwatch->enabled())->toBeTrue()
        ->and($success)->toBeArray()
        ->and($throttled)->toBeArray()
        ->and($success['url'])->toContain('/postmaster/install/[redacted]')
        ->and($throttled['url'])->toContain('/postmaster/install/[redacted]')
        ->and(str_contains($nightwatch->executionState->executionPreview, $sentinel))->toBeFalse()
        ->and(str_contains((string) $success['url'], $sentinel))->toBeFalse()
        ->and(str_contains((string) $success['context'], $sentinel))->toBeFalse()
        ->and(str_contains((string) $throttled['url'], $sentinel))->toBeFalse()
        ->and(str_contains((string) $throttled['context'], $sentinel))->toBeFalse()
        ->and(str_contains($recordedOutput, $sentinel))->toBeFalse();
});

test('authenticated users and guests share the install limit for an ip', function (): void {
    $ip = '198.51.100.17';

    foreach (range(1, 5) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->get(route('postmaster.install', ['token' => 'guest-'.$attempt]))
            ->assertGone();
    }

    foreach ([capstanUser(), capstanUser()] as $userIndex => $user) {
        $this->actingAs($user);

        foreach (range(1, 5) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->get(route('postmaster.install', ['token' => "user-{$userIndex}-{$attempt}"]))
                ->assertGone();
        }
    }

    $this->flushSession();
    $this->app['auth']->forgetGuards();
    $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->get(route('postmaster.install', ['token' => 'guest-16']))
        ->assertTooManyRequests();

    foreach (range(1, 15) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.18'])
            ->get(route('postmaster.install', ['token' => 'other-ip-'.$attempt]))
            ->assertGone();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.18'])
        ->get(route('postmaster.install', ['token' => 'other-ip-16']))
        ->assertTooManyRequests();
});
