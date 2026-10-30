<?php

use App\Enums\PageLayout;
use App\Http\Responses\BrandedErrorResponse;
use App\Support\AppBrand;
use App\Support\PagePublisher;
use App\View\Components\SidebarShell;
use ArtisanBuild\BuiltForCloud\ManagedTransition;
use ArtisanBuild\BuiltForCloud\ManagedTransitionDirection;
use ArtisanBuild\BuiltForCloud\ManagedTransitionStatus;
use ArtisanBuild\BuiltForCloud\UserRole;
use ArtisanBuild\BuiltForCloud\View\Layout;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\View\ComponentSlot;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('s', 32)),
        'app.url' => 'https://shell.test',
        'capstan.artifacts.render_origin' => 'https://render.shell.test',
    ]);
    Feature::flushCache();
    Storage::fake();
});

function expectAuthenticatedShell(string $html, string $proof): void
{
    expect(substr_count($html, 'data-testid="app-shell"'))->toBe(1)
        ->and(substr_count($html, '<!DOCTYPE html>'))->toBe(1)
        ->and(substr_count($html, 'data-testid="app-shell-brand"'))->toBe(1)
        ->and(substr_count($html, 'data-testid="app-shell-content"'))->toBe(1)
        ->and(substr_count($html, 'data-testid="app-shell-sidebar"'))->toBe(1)
        ->and(substr_count($html, 'data-testid="bfc-mobile-drawer-open"'))->toBe(1)
        ->and($html)->toContain($proof, 'img/scalpels-wordmark.webp');
}

function expectGuestShell(string $html): void
{
    expect(substr_count($html, 'data-testid="app-shell"'))->toBe(1)
        ->and(substr_count($html, 'data-testid="app-shell-brand"'))->toBe(1)
        ->and(substr_count($html, 'data-testid="app-shell-content"'))->toBe(1)
        ->and($html)->not->toContain('data-testid="app-shell-sidebar"')
        ->not->toContain('data-testid="bfc-mobile-drawer-open"');
}

function currentShellSection(string $html): ?string
{
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $current = $xpath->query('//a[@aria-current="page"]');

    expect($current->length)->toBeLessThanOrEqual(1);

    return $current->length === 1 ? $current->item(0)?->getAttribute('href') : null;
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

    foreach ([$ordinary => $ordinaryProof, $builtForCloud => $builtForCloudProof] as $html => $proof) {
        $document = new DOMDocument;
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);
        expect($xpath->query('//aside[@data-testid="bfc-sidebar" and @popover="auto"]')->length)->toBe(1)
            ->and($xpath->query('//*[@data-testid="bfc-sidebar"]//*[@data-testid="bfc-sidebar-slot"]/nav[@data-testid="app-shell-sidebar"]')->length)->toBe(1)
            ->and($xpath->query('//*[@data-testid="bfc-main"]//*[@data-testid="fixture-slot"]')->item(0)?->textContent)->toBe($proof)
            ->and($xpath->query('//*[@data-testid="bfc-header-scalpels"]/img[@data-testid="app-shell-brand"]')->length)->toBe(1)
            ->and($xpath->query('//*[@data-testid="bfc-sidebar-user"]//*[@data-testid="bfc-user-menu-button"]')->item(0)?->textContent)->toContain($owner->name);
    }
});

test('the consumer shell retains page head and script stacks around its main slot', function (): void {
    $this->actingAsVersioned(capstanUser());
    $proof = 'stack-proof-'.Str::random(16);
    $html = Blade::render(<<<'BLADE'
        <x-layouts::app>
            @push('head')<meta data-testid="fixture-head" content="{{ $proof }}">@endpush
            @push('scripts')<script data-testid="fixture-scripts" type="application/json">@json($proof)</script>@endpush
            <p data-testid="fixture-slot">{{ $proof }}</p>
        </x-layouts::app>
        BLADE, ['proof' => $proof]);
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);

    expect($xpath->query('//head/meta[@data-testid="fixture-head"]')->item(0)?->getAttribute('content'))->toBe($proof)
        ->and($xpath->query('//body/script[@data-testid="fixture-scripts"]')->item(0)?->textContent)->toContain($proof)
        ->and($xpath->query('//*[@data-testid="bfc-main"]//*[@data-testid="fixture-slot"]')->item(0)?->textContent)->toBe($proof);
});

test('the sidebar slot preserves exactly the authorized app destinations and one settings and logout entry', function (UserRole $role, bool $artifacts): void {
    config(['capstan.artifacts.render_origin' => $artifacts ? 'https://render.shell.test' : null]);
    Feature::flushCache();
    $user = capstanUser(['role' => $role->value]);
    $html = $this->actingAsVersioned($user)->get(route('work.index'))->assertOk()->content();
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $targets = [];
    foreach ($xpath->query('//*[@data-testid="bfc-sidebar-slot"]//a') as $link) {
        $targets[] = $link->getAttribute('href');
    }
    $expected = [route('bfc.dashboard'), route('postmaster.map'), route('work.index')];
    if ($artifacts) {
        $expected[] = route('pages.index');
        if ($role !== UserRole::Member) {
            $expected[] = route('branding.show');
        }
    }
    $expected[] = route('bfc.ui.home');

    expect($targets)->toBe($expected)
        ->and($xpath->query('//a[@href="'.route('bfc.ui.home').'"]')->length)->toBe(1)
        ->and($xpath->query('//form[@action="'.route('bfc.ui.logout').'"]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-testid="bfc-user-menu"]/form[@method="POST"][@action="'.route('bfc.ui.logout').'"]/input[@name="_token"]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-testid="bfc-header-scalpels"]')->item(0)?->getAttribute('href'))->toBe(Layout::SCALPELS_URL)
        ->and($xpath->query('//*[@data-testid="bfc-header-docs"]')->item(0)?->getAttribute('href'))->toBe(Layout::SCALPELS_DOCS_URL.'/capstan');
})->with([UserRole::Owner, UserRole::Admin, UserRole::Member])->with([false, true]);

test('the consumer override renders string sidebar content and the bottom footer slot', function (bool $hasSidebar): void {
    $proof = 'sidebar-'.Str::random(16);
    $footerProof = 'footer-'.Str::random(16);
    $layout = new SidebarShell(resolve(AppBrand::class)->current());
    $html = $layout->render()->with($layout->data() + [
        'slot' => new ComponentSlot('main-'.Str::random(16)),
        'sidebar' => $hasSidebar ? $proof : '',
        'sidebarFooter' => new HtmlString('<a data-testid="fixture-footer" href="/fixture">'.$footerProof.'</a>'),
    ])->render();
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);

    expect($xpath->query('//*[@data-testid="bfc-sidebar-slot"]')->length)->toBe($hasSidebar ? 1 : 0);
    if ($hasSidebar) {
        expect($xpath->query('//*[@data-testid="bfc-sidebar-slot"]')->item(0)?->textContent)->toBe($proof);
    }
    expect($xpath->query('//*[@data-testid="bfc-sidebar-bottom"]//*[@data-testid="fixture-footer"]')->item(0)?->textContent)->toBe($footerProof);
})->with([true, false]);

test('the consumer shell keeps console, product and deployment navigation separate', function (?string $deployment): void {
    $issuer = 'https://console-'.Str::lower(Str::random(8)).'.test';
    config([
        'built-for-cloud.console.issuer' => $issuer,
        'built-for-cloud.vitals.deployment_id' => $deployment,
    ]);
    $html = $this->actingAsVersioned(capstanUser())->get(route('bfc.dashboard'))->assertOk()->content();
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $docs = $issuer.'/docs/capstan'.($deployment === null ? '' : '?from='.rawurlencode($deployment));

    expect($xpath->query('//*[@data-testid="bfc-header-scalpels"]')->item(0)?->getAttribute('href'))->toBe($issuer.'/dashboard')
        ->and($xpath->query('//*[@data-testid="bfc-header-app"]')->item(0)?->getAttribute('href'))->toBe(route('bfc.dashboard'))
        ->and($xpath->query('//*[@data-testid="bfc-sidebar-bottom"]//*[@data-testid="bfc-header-docs"]')->item(0)?->getAttribute('href'))->toBe($docs)
        ->and($xpath->query('//*[@data-testid="bfc-sidebar-manage"]')->length)->toBe($deployment === null ? 0 : 1);
    if ($deployment !== null) {
        expect($xpath->query('//*[@data-testid="bfc-sidebar-bottom"]//*[@data-testid="bfc-sidebar-manage"]')->item(0)?->getAttribute('href'))->toBe($issuer.'/deployments/'.rawurlencode($deployment).'/manage');
    }
})->with([null, 'shell deployment/fixture']);

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
        'sign in' => [route('bfc.login'), null],
        'password recovery' => [route('bfc.password.request'), null],
        'dashboard' => [route('bfc.dashboard'), route('bfc.dashboard')],
        'postmaster' => [route('postmaster.map'), route('postmaster.map')],
        'work' => [route('work.index'), route('work.index')],
        'pages' => [route('pages.index'), route('pages.index')],
        'page viewer' => [route('pages.show', $page), route('pages.index')],
        'page version' => [route('pages.versions.show', [$page, $page->latestVersion]), route('pages.index')],
        'branding' => [route('branding.show'), route('branding.show')],
        'account' => [route('bfc.ui.home'), route('bfc.ui.home')],
        'personal credentials' => [route('bfc.ui.personal-credentials.index'), route('bfc.ui.home')],
        'installation credentials' => [route('bfc.ui.installation-credentials.index'), route('bfc.ui.home')],
        'members' => [route('bfc.members.index'), route('bfc.ui.home')],
        'sessions' => [route('bfc.sessions.index'), route('bfc.ui.home')],
        'adopt authority' => [route('bfc.transitions.index', 'adopt'), route('bfc.ui.home')],
        'exit authority' => [route('bfc.transitions.index', 'exit'), route('bfc.ui.home')],
        'authority proposal' => [route('bfc.transitions.edit', $transition), route('bfc.ui.home')],
        'device authorization' => [route('bfc.device.show'), route('bfc.ui.home')],
        'loopback authorization' => [route('bfc.loopback.authorize'), route('bfc.ui.home')],
    ];

    $this->actingAsVersioned($owner);
    $this->get(route('bfc.landing'))->assertRedirect(route('bfc.dashboard'));

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
