<?php

use App\Enums\PageLayout;
use App\Features\Artifacts;
use App\Http\Middleware\NoIndexArtifactPaths;
use App\Models\Page;
use App\Support\ArtifactRenderOrigin;
use App\Support\PagePublisher;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
        'app.url' => 'https://app.pages.test',
        'capstan.features.artifacts' => true,
        'capstan.artifacts.render_origin' => 'https://render.pages.test',
    ]);
    Feature::flushCache();
    Storage::fake();
});

function ownedPage(User $owner, string $html, string $recipe, string $title): Page
{
    return resolve(PagePublisher::class)->publish(
        pageId: (string) Str::uuid(),
        ownerUserId: (string) $owner->getKey(),
        title: $title,
        description: 'Test-created description',
        refreshRecipe: $recipe,
        layout: PageLayout::Plain,
        content: $html,
        contentType: 'text/html',
    );
}

test('owner sees latest and prior immutable versions while another user sees neither', function (): void {
    $alice = capstanUser(['name' => 'Alice']);
    $bob = capstanUser(['name' => 'Bob']);
    $title = 'Owner-only test page '.Str::random(8);
    $firstHtml = '<html><body>private first '.Str::random(12).'</body></html>';
    $secondHtml = '<html><body>private second '.Str::random(12).'</body></html>';
    $firstRecipe = 'First recipe '.Str::random(12);
    $secondRecipe = 'Second recipe '.Str::random(12);
    $page = ownedPage($alice, $firstHtml, $firstRecipe, $title);
    $first = $page->latestVersion()->firstOrFail();
    $immutable = $first->only(['id', 'version_number', 'content_hash', 'storage_key', 'refresh_recipe', 'layout']);

    $page = resolve(PagePublisher::class)->update(
        pageId: $page->id,
        ownerUserId: (string) $alice->getKey(),
        versionId: (string) Str::uuid(),
        replacements: ['refresh_recipe' => $secondRecipe, 'layout' => PageLayout::Report],
        content: $secondHtml,
        contentType: 'text/html',
    );
    $second = $page->latestVersion()->firstOrFail();

    $latestViewer = $this->actingAsVersioned($alice)
        ->get(route('pages.show', ['page' => $page]))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertSee($title)
        ->assertSee('data-testid="page-viewer"', false)
        ->assertSee('sandbox="allow-scripts"', false)
        ->assertDontSee('allow-same-origin', false)
        ->assertSee('<meta name="robots" content="noindex, nofollow, noarchive">', false);

    expect(substr_count($latestViewer->content(), 'data-testid="page-viewer"'))->toBe(1)
        ->and($latestViewer->content())->toContain("https://render.pages.test/pages/{$page->id}/versions/{$second->id}/content")
        ->and($second->version_number)->toBe(2)
        ->and($page->version_count)->toBe(2)
        ->and($page->refresh_recipe)->toBe($secondRecipe)
        ->and($page->layout)->toBe(PageLayout::Report);

    $priorViewer = $this->actingAsVersioned($alice)
        ->get(route('pages.versions.show', ['page' => $page, 'version' => $first]))
        ->assertOk()
        ->assertSee($title);
    expect($priorViewer->content())->toContain("https://render.pages.test/pages/{$page->id}/versions/{$first->id}/content");

    $latestContent = $this->get(resolve(ArtifactRenderOrigin::class)->signedPageContentUrl($page, $second))
        ->assertOk()
        ->assertStreamed();
    $priorContent = $this->get(resolve(ArtifactRenderOrigin::class)->signedPageContentUrl($page, $first))
        ->assertOk()
        ->assertStreamed();
    expect($latestContent->streamedContent())->toContain(strip_tags($secondHtml), 'data-capstan-brand="applied"', 'data-testid="page-layout-report"')
        ->and($priorContent->streamedContent())->toContain($firstHtml, 'data-capstan-brand="applied"')
        ->and($first->fresh()?->only(array_keys($immutable)))->toBe($immutable);

    $siblingHtml = '<html><body>sibling private '.Str::random(12).'</body></html>';
    $siblingRecipe = 'Sibling recipe '.Str::random(12);
    $sibling = ownedPage($alice, $siblingHtml, $siblingRecipe, 'Sibling page '.Str::random(8));
    $siblingVersion = $sibling->latestVersion()->firstOrFail();
    $wrongParent = $this->actingAsVersioned($alice)
        ->get(route('pages.versions.show', ['page' => $page, 'version' => $siblingVersion]))
        ->assertNotFound();
    $missingVersion = $this->actingAsVersioned($alice)
        ->get(route('pages.versions.show', ['page' => $page, 'version' => Str::uuid()]))
        ->assertNotFound();
    expect($wrongParent->getContent())->toBe($missingVersion->getContent());

    foreach ([$sibling->id, $siblingVersion->id, $siblingHtml, $siblingRecipe] as $privateValue) {
        expect($wrongParent->getContent())->not->toContain($privateValue);
    }

    foreach ([
        route('pages.show', ['page' => $page]),
        route('pages.versions.show', ['page' => $page, 'version' => $first]),
    ] as $url) {
        $response = $this->actingAsVersioned($bob)->get($url)->assertNotFound();

        foreach ([$title, $firstHtml, $secondHtml, $firstRecipe, $secondRecipe] as $privateValue) {
            expect($response->getContent())->not->toContain($privateValue);
        }
    }
});

test('a guest viewer is sent to sign in identically before any page or version query', function (): void {
    $owner = capstanUser();
    $title = 'Guest-private title '.Str::random(10);
    $recipe = 'Guest-private recipe '.Str::random(10);
    $html = '<html><body>Guest-private HTML '.Str::random(10).'</body></html>';
    $page = ownedPage($owner, $html, $recipe, $title);
    $version = $page->latestVersion()->firstOrFail();
    $missingPage = (string) Str::uuid();
    $missingVersion = (string) Str::uuid();
    $urls = [
        route('pages.show', ['page' => $page]),
        route('pages.show', ['page' => $missingPage]),
        route('pages.show', ['page' => 'not-a-uuid']),
        route('pages.versions.show', ['page' => $page, 'version' => $version]),
        route('pages.versions.show', ['page' => $page, 'version' => $missingVersion]),
        route('pages.versions.show', ['page' => $missingPage, 'version' => $missingVersion]),
        route('pages.versions.show', ['page' => 'not-a-uuid', 'version' => 'also-not-a-uuid']),
    ];
    $privateLookups = [];
    DB::listen(function ($query) use (&$privateLookups): void {
        if (str_contains($query->sql, 'from "pages"') || str_contains($query->sql, 'from "page_versions"')) {
            $privateLookups[] = $query->sql;
        }
    });

    // A browser is sent into sign-in so the completed handoff can return it to
    // the URL it asked for; a JSON caller keeps the opaque not-found. Either
    // answer is the same for every page id, and none of them reaches a query.
    foreach (['text/html' => 302, 'application/json' => 404] as $accept => $status) {
        $responses = collect($urls)->map(
            fn (string $url) => $this->get($url, ['Accept' => $accept])->assertStatus($status),
        );
        $expectedBody = $responses->firstOrFail()->getContent();
        $expectedLocation = $responses->firstOrFail()->headers->get('Location');

        if ($accept === 'text/html') {
            expect($expectedLocation)->toBe(route('bfc.login'));
        }
        $expectedCookieNames = collect($responses->firstOrFail()->headers->getCookies())
            ->map(fn ($cookie): string => $cookie->getName())
            ->sort()
            ->values()
            ->all();

        foreach ($responses as $response) {
            expect($response->getContent())->toBe($expectedBody)
                ->and($response->headers->get('Location'))->toBe($expectedLocation)
                ->and(collect($response->headers->getCookies())->map(fn ($cookie): string => $cookie->getName())->sort()->values()->all())
                ->toBe($expectedCookieNames);

            foreach ([$page->id, $version->id, $title, $recipe, $html] as $privateValue) {
                expect($response->getContent())->not->toContain($privateValue);
            }
        }
    }

    $this->actingAsVersioned($owner)
        ->get(route('pages.show', ['page' => 'not-a-uuid']))
        ->assertNotFound();
    $this->actingAsVersioned($owner)
        ->get(route('pages.versions.show', ['page' => $page->id, 'version' => 'not-a-uuid']))
        ->assertNotFound();

    expect($privateLookups)->toBe([]);
});

test('viewer preserves authoritative account admission behind its opaque boundary', function (): void {
    $healthy = capstanUser(['name' => 'Healthy viewer']);
    $stale = capstanUser(['name' => 'Stale viewer']);
    $inactive = capstanUser(['name' => 'Inactive viewer', 'status' => 'inactive']);
    $offboarded = capstanUser(['name' => 'Offboarded viewer']);
    $healthyPage = ownedPage($healthy, '<html><body>healthy private</body></html>', 'Healthy recipe', 'Healthy page');
    $stalePage = ownedPage($stale, '<html><body>stale private</body></html>', 'Stale recipe', 'Stale page');
    $inactivePage = ownedPage($inactive, '<html><body>inactive private</body></html>', 'Inactive recipe', 'Inactive page');
    $offboardedPage = ownedPage($offboarded, '<html><body>offboarded private</body></html>', 'Offboarded recipe', 'Offboarded page');
    OffboardedSubject::query()->create([
        'subject_type' => SubjectType::UserPrincipal,
        'subject_ref' => 'capstan-user:'.$offboarded->getKey(),
        'user_id' => (string) $offboarded->getKey(),
        'offboarded_at' => now(),
    ]);
    $privateLookups = [];
    DB::listen(function ($query) use (&$privateLookups): void {
        if (str_contains($query->sql, 'from "pages"') || str_contains($query->sql, 'from "page_versions"')) {
            $privateLookups[] = $query->sql;
        }
    });

    $healthyResponse = $this->actingAsVersioned($healthy)
        ->get(route('pages.show', ['page' => $healthyPage]))
        ->assertOk()
        ->assertSee('Healthy page');
    expect($healthyResponse->getContent())
        ->toContain("https://render.pages.test/pages/{$healthyPage->id}/versions/")
        ->toContain('signature=', 'expires=');
    $privateLookups = [];

    // A session the installation has already retired is no longer an
    // authenticated human, so it is sent to sign in rather than refused.
    $staleResponse = $this->actingAs($stale)
        ->withSession([StandaloneAccess::SESSION_VERSION_KEY => $stale->auth_session_version + 1])
        ->get(route('pages.show', ['page' => $stalePage]))
        ->assertRedirect(route('bfc.login'))
        ->assertSessionMissing(StandaloneAccess::SESSION_VERSION_KEY);
    $inactiveResponse = $this->actingAsVersioned($inactive)
        ->get(route('pages.show', ['page' => $inactivePage]))
        ->assertNotFound()
        ->assertSessionMissing(StandaloneAccess::SESSION_VERSION_KEY);
    $offboardedResponse = $this->actingAsVersioned($offboarded)
        ->get(route('pages.show', ['page' => $offboardedPage]))
        ->assertNotFound()
        ->assertSessionMissing(StandaloneAccess::SESSION_VERSION_KEY);

    foreach ([$stalePage->id, 'stale private', 'Stale recipe', 'Stale page', 'signature=', 'expires='] as $privateValue) {
        expect($staleResponse->getContent())->not->toContain($privateValue);
    }

    foreach ([
        [$inactiveResponse, $inactivePage, 'inactive private', 'Inactive recipe', 'Inactive page'],
        [$offboardedResponse, $offboardedPage, 'offboarded private', 'Offboarded recipe', 'Offboarded page'],
    ] as [$response, $page, $html, $recipe, $title]) {
        expect($response->getContent())->toBe('');

        foreach ([$page->id, $html, $recipe, $title, 'signature=', 'expires='] as $privateValue) {
            expect($response->getContent())->not->toContain($privateValue);
        }
    }

    expect($privateLookups)->toBe([]);
});

test('page content is signed short lived private streamed and confined to the render host', function (): void {
    $this->travelTo('2026-10-15T12:00:00Z');
    $owner = capstanUser();
    $html = '<html><body><script>window.pageProof=true</script>'.Str::random(10).'</body></html>';
    $recipe = 'Render recipe '.Str::random(8);
    $title = 'Render page '.Str::random(8);
    $page = ownedPage($owner, $html, $recipe, $title);
    $version = $page->latestVersion()->firstOrFail();
    $url = resolve(ArtifactRenderOrigin::class)->signedPageContentUrl($page, $version);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect((int) $query['expires'])->toBe(now()->addMinutes(5)->timestamp);

    $response = $this->get($url)
        ->assertOk()
        ->assertStreamed()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeaderMissing('Set-Cookie');
    expect($response->streamedContent())->toContain($html, 'data-capstan-brand="applied"')
        ->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store', 'no-transform')
        ->and($response->headers->get('Content-Security-Policy'))
        ->toContain("default-src 'none'", "connect-src 'none'", 'frame-ancestors https://app.pages.test')
        ->and($response->headers->getCookies())->toBe([])
        ->and($response->baseResponse->isRedirection())->toBeFalse();

    $servingSource = file_get_contents(app_path('Http/Controllers/PageController.php'));
    expect($servingSource)->not->toContain('temporaryUrl(')
        ->and($servingSource)->not->toContain('->url(');

    $unsigned = "https://render.pages.test/pages/{$page->id}/versions/{$version->id}/content";
    $missing = $this->get('https://render.pages.test/pages/'.Str::uuid().'/versions/'.Str::uuid().'/content')
        ->assertNotFound()
        ->assertHeaderMissing('Set-Cookie');
    $unsignedResponse = $this->get($unsigned)
        ->assertNotFound()
        ->assertHeaderMissing('Set-Cookie');
    $tamperedResponse = $this->get($url.'&tampered=1')
        ->assertNotFound()
        ->assertHeaderMissing('Set-Cookie');

    foreach ([$missing, $unsignedResponse, $tamperedResponse] as $refusal) {
        expect($refusal->getContent())->toBe($missing->getContent())
            ->and($refusal->headers->getCookies())->toBe([]);

        foreach ([$html, $recipe, $title, $version->storage_key, $version->content_hash] as $privateValue) {
            expect($refusal->getContent())->not->toContain($privateValue);
        }
    }

    $this->get("https://app.pages.test/pages/{$page->id}/versions/{$version->id}/content")
        ->assertNotFound()
        ->assertDontSee($html);

    foreach (['/', '/dashboard', "/pages/{$page->id}", "/pages/{$page->id}/versions/{$version->id}", '/pages/not/content'] as $path) {
        $this->get('https://render.pages.test'.$path)->assertNotFound();
    }
});

test('page render route stays sessionless and missing blobs fail closed', function (): void {
    $owner = capstanUser();
    $html = '<html><body>missing page blob '.Str::random(8).'</body></html>';
    $page = ownedPage($owner, $html, 'Missing recipe '.Str::random(8), 'Missing page '.Str::random(8));
    $version = $page->latestVersion()->firstOrFail();
    $route = Route::getRoutes()->getByName('pages.content');
    $middleware = resolve(Router::class)->gatherRouteMiddleware($route);

    expect($middleware)
        ->toContain(SubstituteBindings::class, NoIndexArtifactPaths::class)
        ->not->toContain(StartSession::class);

    foreach ([EncryptCookies::class, ValidateCsrfToken::class] as $statefulMiddleware) {
        expect($middleware)->not->toContain($statefulMiddleware);
    }

    Storage::disk()->delete($version->storage_key);
    $failed = $this->get(resolve(ArtifactRenderOrigin::class)->signedPageContentUrl($page, $version))
        ->assertNotFound();
    foreach ([$version->storage_key, $version->content_hash, 'UnableToRetrieveMetadata', 'UnableToReadFile'] as $privateValue) {
        expect($failed->getContent())->not->toContain($privateValue);
    }
});

test('page viewers and content fail closed when the shared feature or isolated origin is unavailable', function (): void {
    $owner = capstanUser();
    $html = '<html><body>closed page '.Str::random(8).'</body></html>';
    $page = ownedPage($owner, $html, 'Closed recipe '.Str::random(8), 'Closed page '.Str::random(8));
    $version = $page->latestVersion()->firstOrFail();

    config(['capstan.artifacts.render_origin' => null]);
    Feature::flushCache();
    $this->actingAsVersioned($owner)->get(route('pages.show', ['page' => $page]))->assertNotFound();

    config([
        'capstan.artifacts.render_origin' => 'https://app.pages.test',
    ]);
    Feature::flushCache();
    expect(Feature::active(Artifacts::class))->toBeFalse();
    $this->actingAsVersioned($owner)->get(route('pages.show', ['page' => $page]))->assertNotFound();

    config(['capstan.artifacts.render_origin' => null]);
    Feature::flushCache();
    $this->get("https://render.pages.test/pages/{$page->id}/versions/{$version->id}/content")
        ->assertNotFound()
        ->assertDontSee($html);
});
