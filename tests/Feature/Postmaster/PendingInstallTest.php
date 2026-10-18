<?php

use App\Postmaster\PendingInstallLink;
use App\Postmaster\PendingInstallLinks;
use App\Postmaster\ProtectInstallCapabilityTelemetry;
use App\Support\ServerIdentity;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

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
    preg_match("/curl -fsSL '([^']+)' \\| sh/", $install->command, $match);
    expect($match)->toHaveCount(2);

    return (string) str($match[1])->afterLast('/');
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
        ->and($dispatch['params']['command'])->toStartWith("curl -fsSL 'https://capstan.example/postmaster/install/")
        ->toEndWith("' | sh")
        ->and($dispatch['params']['script'])->toStartWith("#!/bin/sh\n(\n")
        ->and($dispatch['params']['expiresAt'])->toBeInt()
        ->and($response->json('components.0.snapshot'))->not->toContain('/postmaster/install/')
        ->and(DB::table('postmaster_installs')->count())->toBe(1);
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

test('the install route redacts its capability before Nightwatch and rate limiting run', function (): void {
    $route = resolve('router')->getRoutes()->getByName('postmaster.install');
    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain(
            ProtectInstallCapabilityTelemetry::class,
            'throttle:15,1',
        )
        ->and(array_search(ProtectInstallCapabilityTelemetry::class, $route->gatherMiddleware(), true))
        ->toBeLessThan(array_search('throttle:15,1', $route->gatherMiddleware(), true))
        ->and(ProtectInstallCapabilityTelemetry::redactUrl('https://capstan.example/postmaster/install/sensitive-token?x=1'))
        ->toBe('https://capstan.example/postmaster/install/[redacted]?x=1')
        ->not->toContain('sensitive-token');
});

test('install fetches are limited to fifteen attempts per minute per ip', function (): void {
    foreach (range(1, 15) as $attempt) {
        $this->get(route('postmaster.install', ['token' => 'unknown-'.$attempt]))->assertGone();
    }

    $this->get(route('postmaster.install', ['token' => 'unknown-16']))->assertTooManyRequests();
});
