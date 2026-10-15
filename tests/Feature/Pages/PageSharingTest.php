<?php

use App\Enums\PageLayout;
use App\Enums\PageShareKind;
use App\Http\Middleware\NoIndexArtifactPaths;
use App\Models\Page;
use App\Models\PageShare;
use App\Notifications\ExternalPageShareNotification;
use App\Support\ArtifactRenderOrigin;
use App\Support\PagePublisher;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('s', 32)),
        'app.url' => 'https://app.sharing.test',
        'capstan.features.artifacts' => true,
        'capstan.artifacts.render_origin' => 'https://render.sharing.test',
    ]);
    Feature::flushCache();
    Storage::fake();
    Notification::fake();
});

function sharingPage(User $owner, string $html, string $title, string $recipe = 'Test-created sharing recipe'): Page
{
    return resolve(PagePublisher::class)->publish(
        pageId: (string) Str::uuid(),
        ownerUserId: (string) $owner->getKey(),
        title: $title,
        description: 'Test-created sharing description '.Str::random(8),
        refreshRecipe: $recipe,
        layout: PageLayout::Plain,
        content: $html,
        contentType: 'text/html',
    );
}

function directShare(Page $page, User $owner, PageShareKind $kind, array $attributes = []): PageShare
{
    return PageShare::query()->create(array_replace([
        'page_id' => $page->id,
        'created_by_user_id' => (string) $owner->getKey(),
        'kind' => $kind,
        'internal_recipient_user_id' => null,
        'recipient_email' => $kind === PageShareKind::External ? 'recipient-'.Str::random(8).'@example.test' : null,
        'expires_at' => $kind === PageShareKind::Internal ? null : now()->addDays(30),
    ], $attributes));
}

function iframeUrl(string $html): string
{
    preg_match('/<iframe[^>]+src="([^"]+)"/', $html, $matches);
    expect($matches)->toHaveKey(1);

    return html_entity_decode($matches[1], ENT_QUOTES);
}

test('page shares use uuid relations indexes and database-enforced exclusive targets', function (): void {
    $owner = capstanUser();
    $recipient = capstanUser();
    $page = sharingPage($owner, '<html><body>schema share</body></html>', 'Schema share '.Str::random(8));
    $share = directShare($page, $owner, PageShareKind::Internal, [
        'internal_recipient_user_id' => (string) $recipient->getKey(),
    ]);
    $indexes = collect(Schema::getIndexes('page_shares'))->pluck('name');

    expect(Str::isUuid($share->id))->toBeTrue()
        ->and($share->page->is($page))->toBeTrue()
        ->and($share->internalRecipient?->is($recipient))->toBeTrue()
        ->and($indexes)->toContain(
            'page_shares_page_id_created_at_index',
            'page_shares_page_id_revoked_at_index',
            'page_shares_internal_active_index',
        );

    expect(fn () => DB::table('page_shares')->insert([
        'id' => (string) Str::uuid(),
        'page_id' => $page->id,
        'created_by_user_id' => $owner->getKey(),
        'kind' => 'internal',
        'internal_recipient_user_id' => $recipient->getKey(),
        'recipient_email' => 'forbidden@example.test',
        'expires_at' => now()->addDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    $page->delete();
    $this->assertDatabaseMissing('page_shares', ['id' => $share->id]);
});

test('owner creates distinct internal external and link grants with exact external mail routes', function (): void {
    $this->travelTo('2026-10-15T12:00:00Z');
    $owner = capstanUser();
    $internal = capstanUser(['email' => 'internal-recipient@example.test']);
    $page = sharingPage($owner, '<html><body>owner controls</body></html>', 'Owner controls '.Str::random(8));

    $this->actingAsVersioned($owner)->post(route('pages.shares.store', ['page' => $page]), [
        'kind' => 'internal',
        'internal_email' => strtoupper($internal->email),
    ])->assertRedirect(route('pages.show', ['page' => $page]));
    $this->actingAsVersioned($owner)->post(route('pages.shares.store', ['page' => $page]), [
        'kind' => 'external',
        'recipient_email' => 'first-recipient@example.test',
    ])->assertRedirect();
    $chosenExpiry = now()->addMonths(2)->startOfSecond();
    $this->actingAsVersioned($owner)->post(route('pages.shares.store', ['page' => $page]), [
        'kind' => 'external',
        'recipient_email' => 'second-recipient@example.test',
        'expires_at' => $chosenExpiry->toIso8601String(),
    ])->assertRedirect();
    $this->actingAsVersioned($owner)->post(route('pages.shares.store', ['page' => $page]), [
        'kind' => 'link',
    ])->assertRedirect();

    $shares = PageShare::query()->orderBy('created_at')->get();
    $external = $shares->where('kind', PageShareKind::External)->values();
    $firstExternal = $external->firstWhere('recipient_email', 'first-recipient@example.test');
    $secondExternal = $external->firstWhere('recipient_email', 'second-recipient@example.test');
    $link = $shares->firstWhere('kind', PageShareKind::Link);
    expect($shares)->toHaveCount(4)
        ->and($shares->firstWhere('kind', PageShareKind::Internal)?->internal_recipient_user_id)->toBe($internal->getKey())
        ->and($external)->toHaveCount(2)
        ->and($firstExternal?->expires_at?->timestamp)->toBe(now()->addDays(30)->timestamp)
        ->and($secondExternal?->expires_at?->timestamp)->toBe($chosenExpiry->timestamp)
        ->and($link?->expires_at?->timestamp)->toBe(now()->addDays(30)->timestamp);

    $sent = [];
    $notifications = [];
    Notification::assertSentOnDemand(
        ExternalPageShareNotification::class,
        function (ExternalPageShareNotification $notification, array $channels, AnonymousNotifiable $notifiable) use (&$sent, &$notifications): bool {
            $sent[] = [$notifiable->routes['mail'] ?? null, $notification->shareId, $notification->viewerUrl];
            $notifications[] = $notification;

            return true;
        },
    );
    expect($sent)->toHaveCount(2)
        ->and(array_column($sent, 0))->toBe(['first-recipient@example.test', 'second-recipient@example.test'])
        ->and($sent[0][1])->not->toBe($sent[1][1])
        ->and($sent[0][2])->not->toBe($sent[1][2]);
    Notification::assertSentOnDemandTimes(ExternalPageShareNotification::class, 2);
    $mail = $notifications[0]->toMail(new AnonymousNotifiable);
    expect($mail->subject)->toBe('A page was shared with you')
        ->and($mail->actionUrl)->toBe($sent[0][2])
        ->and($mail->introLines)->toContain($page->title);
    foreach ([$page->refresh_recipe, $page->latestVersion->storage_key, $page->latestVersion->content_hash, $sent[1][2], 'second-recipient@example.test'] as $needle) {
        expect(implode(' ', $mail->introLines).$mail->actionUrl)->not->toContain($needle);
    }

    $viewer = $this->actingAsVersioned($owner)->get(route('pages.show', ['page' => $page]))
        ->assertOk()
        ->assertSee('data-testid="page-sharing"', false)
        ->assertSee('data-testid="page-shares"', false)
        ->assertSee('data-testid="page-export"', false)
        ->assertSee($internal->email)
        ->assertSee('first-recipient@example.test');
    $internalGrant = $shares->firstWhere('kind', PageShareKind::Internal);
    expect(substr_count($viewer->content(), 'data-testid="page-share-record"'))->toBe(4)
        ->and($viewer->content())->not->toContain('/page-shares/'.$internalGrant?->id.'?');

    $firstGrant = $external->firstWhere('recipient_email', 'first-recipient@example.test');
    $this->actingAsVersioned($owner)->delete('https://app.sharing.test'.route('pages.shares.destroy', [
        'page' => $page,
        'share' => $firstGrant,
    ], false))->assertRedirect();
    $this->get($sent[0][2])->assertNotFound();
    $this->get($sent[1][2])->assertOk();
    $this->get(resolve(ArtifactRenderOrigin::class)->signedPageShareViewerUrl($link))->assertOk();
});

test('invalid expiries and invalid field combinations create no grant or mail', function (array $payload): void {
    $owner = capstanUser();
    $page = sharingPage($owner, '<html><body>invalid expiry</body></html>', 'Invalid expiry '.Str::random(8));

    $this->actingAsVersioned($owner)
        ->from(route('pages.show', ['page' => $page]))
        ->post(route('pages.shares.store', ['page' => $page]), $payload)
        ->assertRedirect(route('pages.show', ['page' => $page]))
        ->assertSessionHasErrors();

    expect(PageShare::query()->count())->toBe(0);
    Notification::assertNothingSent();
})->with([
    'past' => [['kind' => 'link', 'expires_at' => '2020-01-01T00:00:00Z']],
    'not an instant' => [['kind' => 'external', 'recipient_email' => 'x@example.test', 'expires_at' => 'not-a-date']],
    'over one year' => [['kind' => 'link', 'expires_at' => '2030-01-01T00:00:00Z']],
    'internal public expiry' => [['kind' => 'internal', 'internal_email' => 'x@example.test', 'expires_at' => '2026-10-16T00:00:00Z']],
    'link recipient' => [['kind' => 'link', 'recipient_email' => 'x@example.test']],
]);

test('foreign malformed and wrong-parent owner actions are opaque and side-effect free', function (): void {
    $owner = capstanUser();
    $foreign = capstanUser();
    $page = sharingPage($owner, '<html><body>opaque owner action</body></html>', 'Opaque owner action '.Str::random(8));
    $other = sharingPage($owner, '<html><body>other page</body></html>', 'Other page '.Str::random(8));
    $share = directShare($other, $owner, PageShareKind::Link);

    $responses = [
        $this->post(route('pages.shares.store', ['page' => $page]), ['kind' => 'link']),
        $this->actingAsVersioned($foreign)->post(route('pages.shares.store', ['page' => $page]), ['kind' => 'link']),
        $this->actingAsVersioned($owner)->post('/pages/not-a-uuid/shares', ['kind' => 'link']),
        $this->actingAsVersioned($owner)->delete(route('pages.shares.destroy', ['page' => $page, 'share' => $share])),
        $this->actingAsVersioned($owner)->delete(route('pages.shares.destroy', ['page' => $page, 'share' => 'not-a-uuid'])),
        $this->actingAsVersioned($owner)->delete(route('pages.shares.destroy', ['page' => $page, 'share' => Str::uuid()])),
    ];

    foreach ($responses as $response) {
        $response->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        expect($response->getContent())->toBe('');
    }

    expect(PageShare::query()->count())->toBe(1)
        ->and($share->fresh()?->revoked_at)->toBeNull();
    Notification::assertNothingSent();
});

test('internal recipient sees only the current viewer and loses app and minted render access on revoke', function (): void {
    $owner = capstanUser();
    $recipient = capstanUser();
    $recipe = 'Internal-only recipe '.Str::random(10);
    $html = '<html><body>internal content '.Str::random(10).'</body></html>';
    $page = sharingPage($owner, $html, 'Internal page '.Str::random(8), $recipe);
    $version = $page->latestVersion()->firstOrFail();
    $share = directShare($page, $owner, PageShareKind::Internal, [
        'internal_recipient_user_id' => (string) $recipient->getKey(),
    ]);

    $viewer = $this->actingAsVersioned($recipient)->get(route('pages.show', ['page' => $page]))
        ->assertOk()
        ->assertSee($page->title)
        ->assertDontSee($recipe)
        ->assertDontSee('data-testid="page-sharing"', false);
    $contentUrl = iframeUrl($viewer->content());
    $this->get($contentUrl)->assertOk()->assertStreamed();
    $this->actingAsVersioned($recipient)
        ->get(route('pages.versions.show', ['page' => $page, 'version' => $version]))
        ->assertNotFound();

    $this->actingAsVersioned($owner)
        ->delete('https://app.sharing.test'.route('pages.shares.destroy', ['page' => $page, 'share' => $share], false))
        ->assertRedirect();
    $this->actingAsVersioned($recipient)->get(route('pages.show', ['page' => $page]))->assertNotFound();
    $this->get($contentUrl)->assertNotFound()->assertHeaderMissing('Set-Cookie');
});

test('one public viewer and render grant always serves latest then revocation stops both', function (): void {
    $this->travelTo('2026-10-15T12:00:00Z');
    $owner = capstanUser(['email' => 'owner-private@example.test']);
    $firstHtml = '<html><body>public v1 '.Str::random(10).'</body></html>';
    $secondHtml = '<html><body>public v2 '.Str::random(10).'</body></html>';
    $recipe = 'Public-private recipe '.Str::random(10);
    $page = sharingPage($owner, $firstHtml, 'Public target '.Str::random(8), $recipe);
    $share = directShare($page, $owner, PageShareKind::External, [
        'recipient_email' => 'private-recipient@example.test',
        'expires_at' => now()->addHour(),
    ]);
    $other = directShare($page, $owner, PageShareKind::Link);
    $viewerUrl = resolve(ArtifactRenderOrigin::class)->signedPageShareViewerUrl($share);

    $viewer = $this->get($viewerUrl)
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertHeaderMissing('Set-Cookie')
        ->assertSee('data-testid="page-shared-viewer"', false)
        ->assertSee($page->title)
        ->assertSee('sandbox="allow-scripts"', false)
        ->assertDontSee('allow-same-origin', false)
        ->assertSee('<meta name="robots" content="noindex, nofollow, noarchive">', false);
    foreach ([$recipe, $owner->email, $share->recipient_email, $other->id, 'page-sharing'] as $needle) {
        expect($viewer->content())->not->toContain($needle);
    }

    $contentUrl = iframeUrl($viewer->content());
    $first = $this->get($contentUrl)
        ->assertOk()
        ->assertStreamed()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeaderMissing('Set-Cookie');
    expect($first->streamedContent())->toBe($firstHtml)
        ->and($first->headers->get('Cache-Control'))->toContain('private', 'no-store', 'no-transform')
        ->and($first->headers->get('Content-Security-Policy'))->toContain("default-src 'none'", "connect-src 'none'");

    resolve(PagePublisher::class)->update(
        pageId: $page->id,
        ownerUserId: (string) $owner->getKey(),
        versionId: (string) Str::uuid(),
        replacements: [],
        content: $secondHtml,
        contentType: 'text/html',
    );
    expect($this->get($viewerUrl)->assertOk()->content())->toContain($page->title);
    expect($this->get($contentUrl)->assertOk()->streamedContent())->toBe($secondHtml);

    $share->forceFill(['revoked_at' => now()])->save();
    $this->get($viewerUrl)->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    $this->get($contentUrl)->assertNotFound()->assertHeaderMissing('Set-Cookie');
});

test('expired public grants and invalid signatures are opaque before private lookup', function (): void {
    $owner = capstanUser();
    $html = '<html><body>expiry secret '.Str::random(10).'</body></html>';
    $recipe = 'Expiry recipe '.Str::random(10);
    $page = sharingPage($owner, $html, 'Expiry title '.Str::random(8), $recipe);
    $share = directShare($page, $owner, PageShareKind::External, [
        'recipient_email' => 'expiry-recipient@example.test',
        'expires_at' => now()->addMinute(),
    ]);
    $otherShare = directShare($page, $owner, PageShareKind::Link);
    $viewerUrl = resolve(ArtifactRenderOrigin::class)->signedPageShareViewerUrl($share);
    $contentUrl = iframeUrl($this->get($viewerUrl)->assertOk()->content());
    parse_str((string) parse_url($contentUrl, PHP_URL_QUERY), $contentQuery);
    expect((int) $contentQuery['expires'])->toBe($share->expires_at?->timestamp);
    $lookups = [];
    DB::listen(function ($query) use (&$lookups): void {
        if (str_contains($query->sql, 'page_shares')) {
            $lookups[] = $query->sql;
        }
    });

    $invalid = $this->get($viewerUrl.'&tampered=1')->assertNotFound();
    expect($lookups)->toBe([]);

    $this->travel(2)->minutes();
    $expiredViewer = $this->get($viewerUrl)->assertNotFound();
    $expiredContent = $this->get($contentUrl)->assertNotFound();
    foreach ([$invalid, $expiredViewer, $expiredContent] as $response) {
        foreach ([$page->title, $recipe, $html, $share->recipient_email, $share->id, $otherShare->id, $page->latestVersion->storage_key, $page->latestVersion->content_hash, 'signature='] as $needle) {
            expect($response->getContent())->not->toContain((string) $needle);
        }
    }
});

test('public viewer and render routes are sessionless and cookieless by construction', function (): void {
    foreach (['page-shares.show', 'page-shares.content'] as $name) {
        $route = Route::getRoutes()->getByName($name);
        $middleware = resolve(Router::class)->gatherRouteMiddleware($route);
        expect($middleware)->toContain(SubstituteBindings::class, NoIndexArtifactPaths::class)
            ->not->toContain(StartSession::class)
            ->not->toContain(EncryptCookies::class)
            ->not->toContain(ValidateCsrfToken::class);
    }

    $blockedViewer = $this->get('https://render.sharing.test/page-shares/'.Str::uuid())
        ->assertNotFound()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertHeaderMissing('Set-Cookie');
    expect($blockedViewer->headers->getCookies())->toBe([]);
});

test('owner exports the current self-contained html while every other principal gets opaque not found', function (): void {
    $owner = capstanUser();
    $foreign = capstanUser();
    $first = '<!doctype html><html><head><style>body{color:#123}</style></head><body>export v1</body></html>';
    $second = '<!doctype html><html><head><style>body{color:#456}</style></head><body>export v2 '.Str::random(8).'</body></html>';
    $page = sharingPage($owner, $first, 'Export page '.Str::random(8));
    resolve(PagePublisher::class)->update(
        pageId: $page->id,
        ownerUserId: (string) $owner->getKey(),
        versionId: (string) Str::uuid(),
        replacements: [],
        content: $second,
        contentType: 'text/html',
    );

    $guestResponse = $this->get(route('pages.export', ['page' => $page]))->assertNotFound();
    $export = $this->actingAsVersioned($owner)->get(route('pages.export', ['page' => $page]))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertHeader('Content-Disposition', 'attachment; filename="page-'.$page->id.'.html"')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    expect($export->getContent())->toBe($second)
        ->and($export->getContent())->not->toContain('app.sharing.test')
        ->and($export->getContent())->not->toContain('render.sharing.test')
        ->and($export->getContent())->not->toContain('/build/assets/');

    $foreignResponse = $this->actingAsVersioned($foreign)->get(route('pages.export', ['page' => $page]))->assertNotFound();
    $malformedResponse = $this->actingAsVersioned($owner)->get('/pages/not-a-uuid/export')->assertNotFound();
    foreach ([$guestResponse, $foreignResponse, $malformedResponse] as $response) {
        expect($response->getContent())->toBe('');
    }
});
