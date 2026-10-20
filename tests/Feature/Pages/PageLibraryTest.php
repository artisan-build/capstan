<?php

use App\Enums\PageLayout;
use App\Enums\PageShareKind;
use App\Models\Page;
use App\Models\PageShare;
use App\Support\ArtifactRenderOrigin;
use App\Support\PagePublisher;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('l', 32)),
        'app.url' => 'https://app.library.test',
        'capstan.features.artifacts' => true,
        'capstan.artifacts.render_origin' => 'https://render.library.test',
    ]);
    Feature::flushCache();
    Storage::fake();
});

function libraryPage(
    User $owner,
    string $title,
    ?string $recipe = null,
    ?string $pageId = null,
    PageLayout $layout = PageLayout::Plain,
): Page {
    return resolve(PagePublisher::class)->publish(
        pageId: $pageId ?? (string) Str::uuid(),
        ownerUserId: (string) $owner->getKey(),
        title: $title,
        description: 'Library description '.Str::random(10),
        refreshRecipe: $recipe ?? 'Library recipe '.Str::random(10),
        layout: $layout,
        content: '<html><body>Library content '.Str::random(10).'</body></html>',
        contentType: 'text/html',
    );
}

function libraryShare(Page $page, User $owner, PageShareKind $kind, array $attributes = []): PageShare
{
    return PageShare::query()->create(array_replace([
        'page_id' => $page->id,
        'created_by_user_id' => (string) $owner->getKey(),
        'kind' => $kind,
        'internal_recipient_user_id' => null,
        'recipient_email' => $kind === PageShareKind::External ? 'private-'.Str::random(8).'@example.test' : null,
        'expires_at' => $kind === PageShareKind::Internal ? null : now()->addMonth(),
    ], $attributes));
}

function libraryRow(string $html, string $testId, string $title): string
{
    $matched = preg_match(
        '/<article[^>]*data-testid="'.preg_quote($testId, '/').'"[^>]*>'
            .'(?:(?!<\/article>).)*'.preg_quote($title, '/').'(?:(?!<\/article>).)*<\/article>/s',
        $html,
        $matches,
    );
    expect($matched)->toBe(1);

    return $matches[0];
}

/** @return list<string> */
function pagesNavigationTags(string $html): array
{
    preg_match_all('/<a\b[^>]*data-testid="pages-navigation"[^>]*>/s', $html, $matches);

    return $matches[0];
}

test('library separates owned and active internal pages without leaking private metadata', function (): void {
    $this->travelTo('2026-10-16T12:00:00Z');
    $viewer = capstanUser();
    $otherOwner = capstanUser();
    $otherRecipient = capstanUser();
    $privateTitle = 'Private owner page '.Str::random(8);
    $sharedOwnerTitle = 'Shared owner page '.Str::random(8);
    $inactiveOwnerTitle = 'Inactive grants page '.Str::random(8);
    $sharedTitle = 'Internal shared page '.Str::random(8);
    $revokedTitle = 'Revoked internal page '.Str::random(8);
    $externalTitle = 'External-only page '.Str::random(8);
    $linkTitle = 'Link-only page '.Str::random(8);
    $otherRecipientTitle = 'Other-recipient page '.Str::random(8);
    $privateRecipe = 'Index-private recipe '.Str::random(12);

    $private = libraryPage($viewer, $privateTitle, $privateRecipe);
    $sharedOwner = libraryPage($viewer, $sharedOwnerTitle);
    libraryShare($sharedOwner, $viewer, PageShareKind::Link);
    $inactiveOwner = libraryPage($viewer, $inactiveOwnerTitle);
    libraryShare($inactiveOwner, $viewer, PageShareKind::Link, ['revoked_at' => now()->subMinute()]);
    libraryShare($inactiveOwner, $viewer, PageShareKind::External, ['expires_at' => now()->subMinute()]);

    $shared = libraryPage($otherOwner, $sharedTitle);
    libraryShare($shared, $otherOwner, PageShareKind::Internal, ['internal_recipient_user_id' => $viewer->getKey()]);
    libraryShare($shared, $otherOwner, PageShareKind::Internal, ['internal_recipient_user_id' => $viewer->getKey()]);
    $revoked = libraryPage($otherOwner, $revokedTitle);
    libraryShare($revoked, $otherOwner, PageShareKind::Internal, [
        'internal_recipient_user_id' => $viewer->getKey(),
        'revoked_at' => now()->subMinute(),
    ]);
    $external = libraryPage($otherOwner, $externalTitle);
    $externalGrant = libraryShare($external, $otherOwner, PageShareKind::External);
    $link = libraryPage($otherOwner, $linkTitle);
    libraryShare($link, $otherOwner, PageShareKind::Link);
    $otherRecipientPage = libraryPage($otherOwner, $otherRecipientTitle);
    libraryShare($otherRecipientPage, $otherOwner, PageShareKind::Internal, [
        'internal_recipient_user_id' => $otherRecipient->getKey(),
    ]);

    Page::query()->whereKey($private->id)->update(['updated_at' => now()->subMinutes(3)]);
    Page::query()->whereKey($sharedOwner->id)->update(['updated_at' => now()->subMinutes(2)]);
    Page::query()->whereKey($inactiveOwner->id)->update(['updated_at' => now()->subMinute()]);

    $response = $this->actingAsVersioned($viewer)
        ->get(route('pages.index'))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertSee('data-testid="pages-library"', false)
        ->assertSee('data-testid="pages-my-section"', false)
        ->assertSee('data-testid="pages-shared-section"', false)
        ->assertSee($privateTitle)
        ->assertSee($sharedOwnerTitle)
        ->assertSee($inactiveOwnerTitle)
        ->assertSee($sharedTitle)
        ->assertSee('datetime="'.$private->fresh()->updated_at->toIso8601String().'"', false)
        ->assertDontSee($revokedTitle)
        ->assertDontSee($externalTitle)
        ->assertDontSee($linkTitle)
        ->assertDontSee($otherRecipientTitle)
        ->assertDontSee($privateRecipe)
        ->assertDontSee($externalGrant->recipient_email)
        ->assertDontSee($shared->latestVersion->storage_key)
        ->assertDontSee($shared->latestVersion->content_hash);

    $inactiveOwnerRow = libraryRow($response->content(), 'pages-my-row', $inactiveOwnerTitle);
    $sharedOwnerRow = libraryRow($response->content(), 'pages-my-row', $sharedOwnerTitle);
    $sharedRecipientRow = libraryRow($response->content(), 'pages-shared-row', $sharedTitle);

    expect(substr_count($response->content(), 'data-testid="pages-my-row"'))->toBe(3)
        ->and(substr_count($response->content(), 'data-testid="pages-shared-row"'))->toBe(1)
        ->and(substr_count($response->content(), $sharedTitle))->toBe(1)
        ->and($inactiveOwnerRow)->toMatch('/>\s*Private\s*</')
        ->and($sharedOwnerRow)->toMatch('/>\s*Shared\s*</')
        ->and($sharedRecipientRow)->toMatch('/>\s*Shared with you\s*</');
});

test('owned and shared lists paginate independently with deterministic tie ordering', function (): void {
    $viewer = capstanUser();
    $owner = capstanUser();
    $updatedAt = now()->startOfSecond();

    foreach (range(1, 21) as $number) {
        $suffix = str_pad((string) $number, 12, '0', STR_PAD_LEFT);
        $owned = libraryPage(
            $viewer,
            'Owned pagination '.$suffix,
            pageId: '10000000-0000-4000-8000-'.$suffix,
        );
        $shared = libraryPage(
            $owner,
            'Shared pagination '.$suffix,
            pageId: '20000000-0000-4000-8000-'.$suffix,
        );
        libraryShare($shared, $owner, PageShareKind::Internal, [
            'internal_recipient_user_id' => $viewer->getKey(),
        ]);
        Page::query()->whereKey([$owned->id, $shared->id])->update(['updated_at' => $updatedAt]);
    }

    $first = $this->actingAsVersioned($viewer)->get(route('pages.index'))
        ->assertOk()
        ->assertSee('Owned pagination 000000000021')
        ->assertSee('Shared pagination 000000000021')
        ->assertDontSee('Owned pagination 000000000001')
        ->assertDontSee('Shared pagination 000000000001')
        ->assertSee('my_pages_page=2', false)
        ->assertSee('shared_pages_page=2', false);
    expect(substr_count($first->content(), 'data-testid="pages-my-row"'))->toBe(20)
        ->and(substr_count($first->content(), 'data-testid="pages-shared-row"'))->toBe(20)
        ->and(strpos($first->content(), 'Owned pagination 000000000021'))
        ->toBeLessThan(strpos($first->content(), 'Owned pagination 000000000020'));

    $this->actingAsVersioned($viewer)
        ->get(route('pages.index', ['my_pages_page' => 2, 'shared_pages_page' => 2]))
        ->assertOk()
        ->assertSee('Owned pagination 000000000001')
        ->assertSee('Shared pagination 000000000001')
        ->assertDontSee('Owned pagination 000000000002')
        ->assertDontSee('Shared pagination 000000000002');
});

test('library admission failures are opaque and happen before page share or version lookup', function (): void {
    $healthy = capstanUser();
    $stale = capstanUser();
    $inactive = capstanUser(['status' => 'inactive']);
    $offboarded = capstanUser();
    $privatePage = libraryPage($healthy, 'Admission-private title '.Str::random(8));
    OffboardedSubject::query()->create([
        'subject_type' => SubjectType::UserPrincipal,
        'subject_ref' => 'capstan-user:'.$offboarded->getKey(),
        'user_id' => (string) $offboarded->getKey(),
        'offboarded_at' => now(),
    ]);
    $privateLookups = [];
    DB::listen(function ($query) use (&$privateLookups): void {
        if (preg_match('/from ["`]?pages["`]?|from ["`]?page_(?:versions|shares)["`]?/', $query->sql) === 1) {
            $privateLookups[] = $query->sql;
        }
    });

    $responses = [];
    $responses[] = $this->get(route('pages.index'));

    config(['capstan.artifacts.render_origin' => null]);
    Feature::flushCache();
    $responses[] = $this->actingAsVersioned($healthy)->get(route('pages.index'));

    config([
        'capstan.artifacts.render_origin' => null,
    ]);
    Feature::flushCache();
    $responses[] = $this->actingAsVersioned($healthy)->get(route('pages.index'));

    config(['capstan.artifacts.render_origin' => 'https://render.library.test']);
    Feature::flushCache();
    $responses[] = $this->actingAs($stale)
        ->withSession([StandaloneAccess::SESSION_VERSION_KEY => $stale->auth_session_version + 1])
        ->get(route('pages.index'));
    $responses[] = $this->actingAsVersioned($inactive)->get(route('pages.index'));
    $responses[] = $this->actingAsVersioned($offboarded)->get(route('pages.index'));
    $responses[] = $this->actingAsVersioned($healthy)->get('https://render.library.test/pages');

    foreach ($responses as $response) {
        $response->assertNotFound()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        expect($response->getContent())->toBe('')->not->toContain($privatePage->title);
    }

    expect($privateLookups)->toBe([]);
});

test('pages navigation is feature gated and current in sidebar and header variants', function (): void {
    $user = capstanUser();

    $sidebar = $this->actingAsVersioned($user)->get(route('pages.index'))
        ->assertOk()
        ->assertSee('data-testid="pages-navigation"', false)
        ->assertSee('href="'.route('pages.index').'"', false);
    $sidebarPagesTags = pagesNavigationTags($sidebar->content());
    expect($sidebarPagesTags)->toHaveCount(1)
        ->and($sidebarPagesTags[0])->toContain('aria-current="page"');

    $dashboardPagesTags = pagesNavigationTags(
        $this->actingAsVersioned($user)->get(route('bfc.dashboard'))->assertOk()->content(),
    );
    expect($dashboardPagesTags)->toHaveCount(1)
        ->and($dashboardPagesTags[0])->not->toContain('aria-current="page"');

    Route::get('/_tests/pages-header', fn () => view('layouts.app.header', [
        'slot' => new HtmlString('<main data-testid="header-test-body"></main>'),
    ]))->name('pages.header-test');
    $header = $this->actingAsVersioned($user)->get('/_tests/pages-header')
        ->assertOk()
        ->assertSee('data-testid="header-test-body"', false);
    $headerPagesTags = pagesNavigationTags($header->content());
    expect($headerPagesTags)->toHaveCount(1);
    foreach ($headerPagesTags as $tag) {
        expect($tag)->toContain('aria-current="page"');
    }

    config(['capstan.artifacts.render_origin' => null]);
    Feature::flushCache();
    $this->actingAsVersioned($user)
        ->get(route('bfc.dashboard'))
        ->assertOk()
        ->assertDontSee('data-testid="pages-navigation"', false);
    $this->actingAsVersioned($user)
        ->get('/_tests/pages-header')
        ->assertOk()
        ->assertDontSee('data-testid="pages-navigation"', false);
});

test('empty library sections expose stable structural states', function (): void {
    $this->actingAsVersioned(capstanUser())
        ->get(route('pages.index'))
        ->assertOk()
        ->assertSee('data-testid="pages-my-empty"', false)
        ->assertSee('data-testid="pages-shared-empty"', false)
        ->assertDontSee('data-testid="pages-my-row"', false)
        ->assertDontSee('data-testid="pages-shared-row"', false);
});

test('owner viewer shows bounded newest-first history and controls while recipients do not', function (): void {
    $owner = capstanUser();
    $recipient = capstanUser();
    $title = 'History page '.Str::random(8);
    $description = 'Library description';
    $finalRecipe = 'Final owner recipe '.Str::random(10);
    $page = libraryPage($owner, $title, layout: PageLayout::Report);

    foreach (range(2, 27) as $number) {
        $page = resolve(PagePublisher::class)->update(
            pageId: $page->id,
            ownerUserId: (string) $owner->getKey(),
            versionId: (string) Str::uuid(),
            replacements: [
                'refresh_recipe' => $number === 27 ? $finalRecipe : 'History recipe '.$number,
                'layout' => PageLayout::Report,
            ],
            content: '<html><body>History version '.$number.'</body></html>',
            contentType: 'text/html',
        );
    }
    libraryShare($page, $owner, PageShareKind::Internal, [
        'internal_recipient_user_id' => $recipient->getKey(),
    ]);

    $ownerViewer = $this->actingAsVersioned($owner)
        ->get(route('pages.show', ['page' => $page]))
        ->assertOk()
        ->assertSee($title)
        ->assertSee($description)
        ->assertSee('data-testid="page-owner-metadata"', false)
        ->assertSee('data-testid="page-refresh-recipe"', false)
        ->assertSee('data-testid="page-version-history"', false)
        ->assertSee('data-testid="page-sharing"', false)
        ->assertSee('data-testid="page-export"', false)
        ->assertSee('data-testid="page-delete"', false)
        ->assertSee($finalRecipe)
        ->assertSee('Version 27')
        ->assertSee('Version 3')
        ->assertDontSee('>Version 2</a>', false)
        ->assertSee('versions_page=2', false);
    expect(substr_count($ownerViewer->content(), 'data-testid="page-version-record"'))->toBe(25)
        ->and(strpos($ownerViewer->content(), 'Version 27'))
        ->toBeLessThan(strpos($ownerViewer->content(), 'Version 26'));

    $versionViewer = $this->actingAsVersioned($owner)
        ->get(route('pages.versions.show', [
            'page' => $page,
            'version' => $page->versions()->oldest('version_number')->firstOrFail(),
        ]))
        ->assertOk()
        ->assertSee($title)
        ->assertSee('data-testid="page-viewer"', false)
        ->assertSee('data-testid="page-owner-metadata"', false);
    foreach ([$ownerViewer, $versionViewer] as $viewer) {
        $document = new DOMDocument;
        @$document->loadHTML($viewer->content());
        $xpath = new DOMXPath($document);
        $pageViewer = $xpath->query('//*[@data-testid="page-viewer"]')->item(0);
        expect($pageViewer)->not->toBeNull()
            ->and($pageViewer?->textContent)->toContain($title);
        $classes = (array) preg_split('/\s+/', trim($pageViewer?->getAttribute('class') ?? ''));
        foreach ($xpath->query('.//*[@class]', $pageViewer) as $node) {
            array_push($classes, ...(array) preg_split('/\s+/', trim($node->getAttribute('class'))));
        }
        foreach (['bg-neutral-900', 'bg-neutral-800', 'text-neutral-950', 'text-neutral-300', 'text-neutral-400', 'text-neutral-100', 'border-neutral-800', 'border-neutral-700'] as $legacyClass) {
            expect($classes)->not->toContain($legacyClass);
        }
    }

    $this->actingAsVersioned($owner)
        ->get(route('pages.show', ['page' => $page, 'versions_page' => 2]))
        ->assertOk()
        ->assertSee('Version 2')
        ->assertSee('Version 1')
        ->assertDontSee('Version 3');

    $recipientViewer = $this->actingAsVersioned($recipient)
        ->get(route('pages.show', ['page' => $page]))
        ->assertOk()
        ->assertSee($title)
        ->assertSee($description)
        ->assertDontSee($finalRecipe)
        ->assertDontSee('data-testid="page-owner-metadata"', false)
        ->assertDontSee('data-testid="page-refresh-recipe"', false)
        ->assertDontSee('data-testid="page-version-history"', false)
        ->assertDontSee('data-testid="page-sharing"', false)
        ->assertDontSee('data-testid="page-export"', false)
        ->assertDontSee('data-testid="page-delete"', false);
    expect(substr_count($recipientViewer->content(), 'data-testid="page-viewer"'))->toBe(1);
});

test('only the owner can delete and deletion cascades access without deleting shared blob storage', function (): void {
    $owner = capstanUser();
    $recipient = capstanUser();
    $foreign = capstanUser();
    $title = 'Deleted private page '.Str::random(8);
    $recipe = 'Deleted private recipe '.Str::random(8);
    $page = libraryPage($owner, $title, $recipe);
    $firstVersion = $page->latestVersion()->firstOrFail();
    $page = resolve(PagePublisher::class)->update(
        pageId: $page->id,
        ownerUserId: (string) $owner->getKey(),
        versionId: (string) Str::uuid(),
        replacements: [],
        content: '<html><body>Delete current content</body></html>',
        contentType: 'text/html',
    );
    $currentVersion = $page->latestVersion()->firstOrFail();
    $internal = libraryShare($page, $owner, PageShareKind::Internal, [
        'internal_recipient_user_id' => $recipient->getKey(),
    ]);
    $link = libraryShare($page, $owner, PageShareKind::Link);
    $origin = resolve(ArtifactRenderOrigin::class);
    $renderContentUrl = $origin->signedPageContentUrl($page, $currentVersion);
    $shareViewerUrl = $origin->signedPageShareViewerUrl($link);
    $shareContentUrl = $origin->signedPageShareContentUrl($link);
    $storageKey = $currentVersion->storage_key;

    foreach ([$recipient, $foreign] as $deniedUser) {
        $denied = $this->actingAsVersioned($deniedUser)
            ->delete(route('pages.destroy', ['page' => $page]))
            ->assertNotFound()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        expect($denied->getContent())->toBe('')->not->toContain($title, $recipe);
    }
    expect($page->fresh())->toBeInstanceOf(Page::class);

    $this->actingAsVersioned($owner)
        ->delete(route('pages.destroy', ['page' => $page]))
        ->assertRedirect(route('pages.index'))
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

    $this->assertDatabaseMissing('pages', ['id' => $page->id]);
    $this->assertDatabaseMissing('page_versions', ['id' => $firstVersion->id]);
    $this->assertDatabaseMissing('page_versions', ['id' => $currentVersion->id]);
    $this->assertDatabaseMissing('page_shares', ['id' => $internal->id]);
    $this->assertDatabaseMissing('page_shares', ['id' => $link->id]);
    expect(Storage::disk()->exists($storageKey))->toBeTrue();

    $appResponses = [
        $this->actingAsVersioned($owner)->get(route('pages.show', ['page' => $page->id])),
        $this->actingAsVersioned($owner)->get(route('pages.versions.show', ['page' => $page->id, 'version' => $firstVersion->id])),
        $this->actingAsVersioned($owner)->get(route('pages.export', ['page' => $page->id])),
        $this->actingAsVersioned($owner)->post(route('pages.shares.store', ['page' => $page->id]), ['kind' => 'link']),
        $this->actingAsVersioned($owner)->delete(route('pages.shares.destroy', ['page' => $page->id, 'share' => $internal->id])),
    ];
    $renderResponses = [
        $this->get($renderContentUrl),
        $this->get($shareViewerUrl),
        $this->get($shareContentUrl),
    ];

    foreach ([...$appResponses, ...$renderResponses] as $response) {
        $response->assertNotFound();
        foreach ([$title, $recipe, $storageKey, $currentVersion->content_hash] as $privateValue) {
            expect($response->getContent())->not->toContain($privateValue);
        }
    }
});

test('unexpected library failures remain noindex', function (): void {
    config(['app.debug' => false]);
    Route::get('/pages', fn () => throw new RuntimeException('Test-created library failure'))
        ->middleware('capstan.noindex_artifacts');

    $this->get('https://app.library.test/pages')
        ->assertServerError()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

    $this->post('https://app.library.test/pages')
        ->assertStatus(405)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
});

test('delete rejects unavailable principals malformed ids and render-host requests without private detail', function (): void {
    $owner = capstanUser();
    $stale = capstanUser();
    $inactive = capstanUser(['status' => 'inactive']);
    $offboarded = capstanUser();
    $title = 'Opaque delete title '.Str::random(8);
    $page = libraryPage($owner, $title);
    OffboardedSubject::query()->create([
        'subject_type' => SubjectType::UserPrincipal,
        'subject_ref' => 'capstan-user:'.$offboarded->getKey(),
        'user_id' => (string) $offboarded->getKey(),
        'offboarded_at' => now(),
    ]);
    $privateLookups = [];
    DB::listen(function ($query) use (&$privateLookups): void {
        if (preg_match('/from ["`]?pages["`]?|from ["`]?page_(?:versions|shares)["`]?/', $query->sql) === 1) {
            $privateLookups[] = $query->sql;
        }
    });

    $responses = [
        $this->delete(route('pages.destroy', ['page' => $page])),
        $this->actingAs($stale)
            ->withSession([StandaloneAccess::SESSION_VERSION_KEY => $stale->auth_session_version + 1])
            ->delete(route('pages.destroy', ['page' => $page])),
        $this->actingAsVersioned($inactive)->delete(route('pages.destroy', ['page' => $page])),
        $this->actingAsVersioned($offboarded)->delete(route('pages.destroy', ['page' => $page])),
        $this->actingAsVersioned($owner)->delete('/pages/not-a-uuid'),
        $this->actingAsVersioned($owner)->delete('https://render.library.test/pages/'.$page->id),
    ];

    config(['capstan.artifacts.render_origin' => null]);
    Feature::flushCache();
    $responses[] = $this->actingAsVersioned($owner)->delete(route('pages.destroy', ['page' => $page]));

    config([
        'capstan.artifacts.render_origin' => null,
    ]);
    Feature::flushCache();
    $responses[] = $this->actingAsVersioned($owner)->delete(route('pages.destroy', ['page' => $page]));

    foreach ($responses as $response) {
        $response->assertNotFound()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        expect($response->getContent())->toBe('')->not->toContain($title, $page->refresh_recipe);
    }
    expect($privateLookups)->toBe([]);

    config(['capstan.artifacts.render_origin' => 'https://render.library.test']);
    Feature::flushCache();
    $unknown = $this->actingAsVersioned($owner)
        ->delete(route('pages.destroy', ['page' => Str::uuid()]))
        ->assertNotFound()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    expect($unknown->getContent())->toBe('')->not->toContain($title, $page->refresh_recipe);
    expect($page->fresh())->toBeInstanceOf(Page::class);
});
