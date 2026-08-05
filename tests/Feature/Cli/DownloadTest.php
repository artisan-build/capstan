<?php

use App\Http\Controllers\Cli\AuthorizeController;
use App\Http\Controllers\Cli\DeviceVerifyController;
use App\Http\Controllers\CliDownloadController;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

const CLI_ASSET = 'cli/v1.2.3/capstan_1.2.3_linux_amd64.tar.gz';

beforeEach(function (): void {
    config(['filesystems.disks.downloads.bucket' => 'capstan-downloads-test']);
    Storage::fake('downloads');
});

test('a fork with no downloads bucket provisioned 404s instead of erroring', function (): void {
    Storage::disk('downloads')->put(CLI_ASSET, 'binary');

    config(['filesystems.disks.downloads.bucket' => null]);

    $this->get('/'.CLI_ASSET)->assertNotFound();
});

test('a published release artifact streams back with download headers', function (): void {
    $bytes = random_bytes(512);
    Storage::disk('downloads')->put(CLI_ASSET, $bytes);

    $response = $this->get('/'.CLI_ASSET)
        ->assertOk()
        ->assertStreamed()
        ->assertHeader('Content-Type', 'application/octet-stream')
        ->assertHeader('Content-Disposition', 'attachment; filename="capstan_1.2.3_linux_amd64.tar.gz"')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Length', (string) strlen($bytes));

    // Versioned paths are immutable, and no intermediary may rewrite the bytes:
    // a mutated binary no longer matches its published checksum/signature.
    expect($response->headers->get('Cache-Control'))
        ->toContain('no-transform')
        ->toContain('immutable')
        ->toContain('max-age=31536000')
        ->and($response->streamedContent())->toBe($bytes);
});

test('an unversioned object is served no-transform but never cached long', function (): void {
    Storage::disk('downloads')->put('cli/latest.json', '{"version":"v1.2.3"}');

    $response = $this->get('/cli/latest.json')->assertOk();

    expect($response->headers->get('Cache-Control'))
        ->toContain('no-transform')
        ->not->toContain('immutable')
        ->not->toContain('max-age=31536000');
});

test('a missing object is a 404, not a 403 and not a hint', function (): void {
    $this->get('/cli/v9.9.9/capstan_9.9.9_darwin_arm64.tar.gz')->assertNotFound();
});

test('there is no directory listing', function (): void {
    Storage::disk('downloads')->put(CLI_ASSET, 'binary');

    $this->get('/cli/v1.2.3')->assertNotFound();
    $this->get('/cli/v1.2.3/')->assertNotFound();
});

test('traversal attempts are refused and never escape the cli prefix', function (): void {
    // A sibling object one level above the cli/ prefix — nothing below may reach it.
    Storage::disk('downloads')->put('secrets/.env', 'APP_KEY=leaked');
    Storage::disk('downloads')->put('.env', 'APP_KEY=leaked');

    $attempts = [
        '/cli/../.env',
        '/cli/..%2F.env',
        '/cli/%2e%2e/.env',
        '/cli/%2e%2e%2f.env',
        '/cli/v1.2.3/../../.env',
        '/cli/..%2f..%2fsecrets%2f.env',
        '/cli//etc/passwd',
        // A raw backslash cannot even reach routing (Symfony rejects the URI),
        // so the wire-level case that matters is the encoded one. The raw form
        // is covered directly against the controller below.
        '/cli/%5c..%5c.env',
        '/cli/v1.2.3%5c..%5c.env',
        "/cli/v1.2.3/binary\0.txt",
        '/cli/v1.2.3/binary%00.txt',
        '/cli/.env',
        '/cli/-rf',
    ];

    foreach ($attempts as $attempt) {
        $response = $this->get($attempt);

        expect($response->status())->toBe(404, "expected 404 for {$attempt}")
            ->and($response->content())->not->toContain('APP_KEY');
    }
});

test('the controller itself refuses hostile paths even without the route constraint', function (): void {
    Storage::disk('downloads')->put('.env', 'APP_KEY=leaked');

    $controller = new CliDownloadController;

    $hostile = ['', '../.env', '/.env', '..\\.env', 'v1\\x', "v1\0/x", "v1/x\0.txt", 'v1/../../.env', './.env', '%2e%2e/.env'];

    foreach ($hostile as $path) {
        expect(fn () => $controller($path))
            ->toThrow(NotFoundHttpException::class);
    }
});

test('the download route does not swallow the CLI auth routes', function (): void {
    $routes = Route::getRoutes();

    $resolves = function (string $method, string $uri) use ($routes): string {
        $request = Request::create($uri, $method);

        return (string) $routes->match($request)->getActionName();
    };

    expect($resolves('GET', '/cli/authorize'))->toStartWith(AuthorizeController::class)
        ->and($resolves('POST', '/cli/authorize'))->toStartWith(AuthorizeController::class)
        ->and($resolves('GET', '/cli/device'))->toStartWith(DeviceVerifyController::class)
        ->and($resolves('POST', '/cli/device'))->toStartWith(DeviceVerifyController::class)
        ->and($resolves('GET', '/'.CLI_ASSET))->toStartWith(CliDownloadController::class);
});

test('the CLI auth routes still enforce their own auth, unaffected by the public route', function (): void {
    // The download route is unauthenticated; the auth routes must still redirect
    // a guest to login rather than falling through to a 404 download.
    $this->get('/cli/authorize')->assertRedirect('/login');
    $this->get('/cli/device')->assertRedirect('/login');
});

test('the API cli token routes are untouched', function (): void {
    $routes = Route::getRoutes();

    foreach (['api/v1/cli/device', 'api/v1/cli/device/token', 'api/v1/cli/authorize/token'] as $uri) {
        $action = $routes->match(Request::create('/'.$uri, 'POST'))->getActionName();

        expect($action)->not->toContain('CliDownloadController');
    }
});

test('the download route is public and carries no auth middleware', function (): void {
    $route = Route::getRoutes()->getByName('cli.download');

    expect($route)->not->toBeNull()
        ->and(app(Router::class)->gatherRouteMiddleware($route))
        ->not->toContain(Authenticate::class);
});
