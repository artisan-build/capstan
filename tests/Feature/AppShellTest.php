<?php

use App\Enums\PageLayout;
use App\Http\Responses\BrandedErrorResponse;
use App\Support\PagePublisher;
use ArtisanBuild\BuiltForCloud\ManagedTransition;
use ArtisanBuild\BuiltForCloud\ManagedTransitionDirection;
use ArtisanBuild\BuiltForCloud\ManagedTransitionStatus;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('s', 32)),
        'app.url' => 'https://shell.test',
        'capstan.features.artifacts' => true,
        'capstan.artifacts.render_origin' => 'https://render.shell.test',
    ]);
    Feature::flushCache();
    Storage::fake();
});

function expectAuthenticatedShell(string $html, string $proof): void
{
    expect(substr_count($html, 'data-testid="app-shell"'))->toBe(1)
        ->and(substr_count($html, 'data-testid="app-shell-brand"'))->toBe(1)
        ->and(substr_count($html, 'data-testid="app-shell-content"'))->toBe(1)
        ->and(substr_count($html, 'data-testid="app-shell-sidebar"'))->toBe(1)
        ->and(substr_count($html, 'data-testid="app-shell-mobile-trigger"'))->toBe(1)
        ->and($html)->toContain($proof, 'img/scalpels-wordmark.webp');
}

function expectGuestShell(string $html): void
{
    expect(substr_count($html, 'data-testid="app-shell"'))->toBe(1)
        ->and(substr_count($html, 'data-testid="app-shell-brand"'))->toBe(1)
        ->and(substr_count($html, 'data-testid="app-shell-content"'))->toBe(1)
        ->and($html)->not->toContain('data-testid="app-shell-sidebar"')
        ->not->toContain('data-testid="app-shell-mobile-trigger"');
}

function currentShellSection(string $html): ?string
{
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $current = $xpath->query('//a[@aria-current="page"]');

    expect($current->length)->toBeLessThanOrEqual(1);

    return $current->length === 1 ? trim($current->item(0)?->textContent ?? '') : null;
}

test('ordinary and Built for Cloud layouts render the same authenticated shell around test-created slots', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $this->actingAsVersioned($owner);
    $ordinaryProof = 'ordinary-slot-'.Str::random(16);
    $builtForCloudProof = 'bfc-slot-'.Str::random(16);

    $ordinary = Blade::render(
        '<x-layouts::app :title="$title"><p data-testid="fixture-slot">{{ $proof }}</p></x-layouts::app>',
        ['title' => Str::random(8), 'proof' => $ordinaryProof],
    );
    $builtForCloud = Blade::render(
        '<x-bfc-layout :title="$title"><p data-testid="fixture-slot">{{ $proof }}</p></x-bfc-layout>',
        ['title' => Str::random(8), 'proof' => $builtForCloudProof],
    );

    expectAuthenticatedShell($ordinary, $ordinaryProof);
    expectAuthenticatedShell($builtForCloud, $builtForCloudProof);

    $document = new DOMDocument;
    @$document->loadHTML($ordinary);
    $xpath = new DOMXPath($document);
    expect($xpath->query('//details[@data-app-shell-navigation and @open]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-testid="app-shell-mobile-trigger"]//*[@data-testid="app-shell-brand"]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-testid="app-shell-mobile-trigger"]//*[@data-testid="app-shell-brand"]//img[@alt="Scalpels dashboard"]')->length)->toBe(1)
        ->and(trim($xpath->query('//*[@data-testid="app-shell-mobile-trigger"]')->item(0)?->textContent ?? ''))->toContain('Menu');
});

test('authenticated HTML routes keep the brand sidebar and current section', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $proof = 'route-page-'.Str::random(16);
    $page = resolve(PagePublisher::class)->publish(
        pageId: (string) Str::uuid(),
        ownerUserId: (string) $owner->getKey(),
        title: $proof,
        description: null,
        refreshRecipe: Str::random(12),
        layout: PageLayout::Plain,
        content: '<p>'.Str::random(12).'</p>',
        contentType: 'text/html',
    );
    $transition = ManagedTransition::query()->create([
        'initiated_by_user_id' => (string) $owner->getKey(),
        'direction' => ManagedTransitionDirection::Adopt,
        'status' => ManagedTransitionStatus::Prepared,
        'issuer' => 'https://authority.shell.test',
        'connection_id' => 'shell-connection',
        'organization_id' => 'shell-organization',
        'installation_id' => 'shell-installation',
        'authority_base_url' => 'https://authority.shell.test',
        'client_credential_reference' => 'shell-reference',
        'mode_before' => 'standalone',
        'mode_after' => 'managed',
        'generation_before' => 1,
        'generation_after' => 2,
        'transition_request_id' => (string) Str::uuid(),
        'prepare_request_body' => '{}',
        'prepare_body_digest' => hash('sha256', '{}'),
    ]);

    $routes = [
        'landing' => [route('bfc.landing'), null],
        'sign in' => [route('bfc.login'), null],
        'password recovery' => [route('bfc.password.request'), null],
        'dashboard' => [route('bfc.dashboard'), 'Dashboard'],
        'postmaster' => [route('postmaster.map'), 'Postmaster'],
        'work' => [route('work.index'), 'Work'],
        'pages' => [route('pages.index'), 'Pages'],
        'page viewer' => [route('pages.show', $page), 'Pages'],
        'page version' => [route('pages.versions.show', [$page, $page->latestVersion]), 'Pages'],
        'branding' => [route('branding.show'), 'Branding'],
        'account' => [route('bfc.ui.home'), 'Account'],
        'personal credentials' => [route('bfc.ui.personal-credentials.index'), 'Account'],
        'installation credentials' => [route('bfc.ui.installation-credentials.index'), 'Account'],
        'members' => [route('bfc.members.index'), 'Account'],
        'sessions' => [route('bfc.sessions.index'), 'Account'],
        'adopt authority' => [route('bfc.transitions.index', 'adopt'), 'Account'],
        'exit authority' => [route('bfc.transitions.index', 'exit'), 'Account'],
        'authority proposal' => [route('bfc.transitions.edit', $transition), 'Account'],
        'device authorization' => [route('bfc.device.show'), 'Account'],
        'loopback authorization' => [route('bfc.loopback.authorize'), 'Account'],
    ];

    $this->actingAsVersioned($owner);

    foreach ($routes as $label => [$url, $section]) {
        $response = $this->get($url);

        expect($response->getStatusCode())->toBeLessThan(500);
        expectAuthenticatedShell($response->content(), $label === 'page viewer' || $label === 'page version' ? $proof : 'data-testid="app-shell-content"');
        expect(currentShellSection($response->content()))->toBe($section);
    }
});

test('branded errors expose no exception detail and add the sidebar only for authenticated users', function (int $status, bool $authenticated): void {
    $proof = 'private-error-'.Str::random(18);
    $path = '/_tests/app-shell-error-'.$status.'-'.Str::lower(Str::random(8));
    Route::middleware('web')->get($path, function () use ($status, $proof): never {
        match ($status) {
            403 => abort(403, $proof),
            404 => abort(404, $proof),
            419 => throw new TokenMismatchException($proof),
            default => throw new RuntimeException($proof),
        };
    });

    if ($authenticated) {
        $this->actingAsVersioned(capstanUser(['role' => UserRole::Owner->value]));
    }

    $response = $this->withoutMiddleware(ValidateCsrfToken::class)->get($path)->assertStatus($status);
    $html = $response->content();

    $authenticated ? expectAuthenticatedShell($html, 'data-testid="error-page"') : expectGuestShell($html);
    expect($html)->toContain('data-testid="error-page"')
        ->not->toContain($proof)
        ->not->toContain(RuntimeException::class)
        ->not->toContain('APP_KEY');
})->with([403, 404, 419, 500])->with([false, true]);

test('raw and render-origin paths are excluded from branded exception responses', function (string $path): void {
    $request = Request::create($path, 'GET', server: ['HTTP_ACCEPT' => 'text/html']);

    expect(resolve(BrandedErrorResponse::class)->render(new RuntimeException(Str::random(20)), $request))->toBeNull();
})->with([
    '/api/v1/work/items/example/brief',
    '/artifacts/example/content',
    '/brand-assets/example',
    '/brand-previews/example',
    '/page-shares/example/content',
    '/pages/example/versions/example/content',
    '/pages/example/export',
    '/bfc/assets/bfc.css',
]);
