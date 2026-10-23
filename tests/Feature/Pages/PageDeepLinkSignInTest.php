<?php

use App\Enums\PageLayout;
use App\Models\Page;
use App\Support\PagePublisher;
use ArtisanBuild\BuiltForCloud\AuthorityMode;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\ManagedAuthClient;
use ArtisanBuild\BuiltForCloud\ManagedHandoff;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config([
        'app.url' => 'https://app.deep-link.test',
        'capstan.features.artifacts' => true,
        'capstan.artifacts.render_origin' => 'https://render.deep-link.test',
        'built-for-cloud.managed.client_secret' => str_repeat('n', 64),
    ]);
    Feature::flushCache();
    Storage::fake();
    DB::table('bfc_authority')->where('key', InstallationAuthority::KEY)->update([
        'mode' => AuthorityMode::Managed->value,
        'generation' => 2,
        'issuer' => deepLinkIssuer(),
        'connection_id' => 'deep-link-connection',
        'organization_id' => 'deep-link-organization',
        'installation_id' => 'deep-link-installation',
        'authority_base_url' => deepLinkIssuer(),
        'managed_connection_status' => 'active',
        'managed_connection_generation' => 2,
        'managed_connection_roster_version' => 1,
        'managed_connection_response_sequence' => 1,
    ]);
});

function deepLinkIssuer(): string
{
    return 'https://scalpels.deep-link.test';
}

/** @param array<string, mixed> $attributes */
function deepLinkMember(array $attributes = []): User
{
    return capstanUser(array_replace([
        'role' => UserRole::Owner->value,
        'scalpels_issuer' => deepLinkIssuer(),
        'scalpels_connection_id' => 'deep-link-connection',
        'scalpels_id' => 'deep-link-'.bin2hex(random_bytes(6)),
        'managed_membership_status' => 'active',
        'managed_membership_role' => UserRole::Owner->value,
        'managed_membership_generation' => 2,
        'managed_membership_roster_version' => 1,
        'managed_membership_response_sequence' => 1,
        'managed_membership_responded_at' => now()->toAtomString(),
        'membership_confirmed_at' => now(),
    ], $attributes));
}

function deepLinkPage(User $owner, string $title): Page
{
    return resolve(PagePublisher::class)->publish(
        pageId: (string) Str::uuid(),
        ownerUserId: (string) $owner->getKey(),
        title: $title,
        description: 'Test-created description',
        refreshRecipe: 'Test-created recipe '.Str::random(8),
        layout: PageLayout::Plain,
        content: '<html><body>Test-created body '.Str::random(10).'</body></html>',
        contentType: 'text/html',
    );
}

/** The Scalpels side of the handoff, stubbed so only our composition is under test. */
function fakeScalpelsHandoff(User $signingIn): void
{
    Http::preventStrayRequests();
    Http::fake([
        deepLinkIssuer().'/managed-auth/v1/handoffs/*/exchange' => Http::response([
            'contract_version' => ManagedAuthClient::CONTRACT_VERSION,
            'issuer' => deepLinkIssuer(),
            'connection_id' => 'deep-link-connection',
            'organization_id' => 'deep-link-organization',
            'installation_id' => 'deep-link-installation',
            'authority_generation' => 2,
            'scalpels_id' => $signingIn->scalpels_id,
            'membership_id' => 'deep-link-membership',
            'membership_status' => 'active',
            'connection_status' => 'active',
            'role' => (string) $signingIn->managed_membership_role,
            'display_name' => (string) $signingIn->name,
            'contact_email' => (string) $signingIn->email,
            'contact_email_verified' => true,
            'roster_version' => 2,
            'response_sequence' => 2,
            'responded_at' => now()->toAtomString(),
        ]),
        deepLinkIssuer().'/managed-auth/v1/handoffs' => fn ($request) => Http::response([
            'contract_version' => ManagedAuthClient::CONTRACT_VERSION,
            'request_id' => $request->data()['request_id'],
            'authorization_url' => deepLinkIssuer().ManagedAuthClient::AUTHORIZE_PATH,
            'expires_at' => now()->addMinutes(5)->toAtomString(),
        ]),
    ]);
}

function deepLinkAuthorizationState(string $location): string
{
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return (string) ($query['state'] ?? '');
}

test('a signed out page deep link comes back to that same page once managed sign in completes', function (): void {
    $owner = deepLinkMember();
    $page = deepLinkPage($owner, 'Deep link page '.Str::random(8));
    $url = route('pages.show', ['page' => $page], absolute: false);
    fakeScalpelsHandoff($owner);

    $this->get($url)
        ->assertRedirect(route('bfc.managed.login', ['intended' => $url]));

    $handoff = $this->get(route('bfc.managed.login', ['intended' => $url]));
    $handoff->assertRedirectContains(deepLinkIssuer().ManagedAuthClient::AUTHORIZE_PATH)
        ->assertSessionHas(ManagedHandoff::SESSION_INTENDED_KEY, $url);

    $this->get(route('bfc.managed.callback', [
        'state' => deepLinkAuthorizationState((string) $handoff->headers->get('Location')),
        'code' => 'deep-link-code',
    ]))->assertRedirect($url);

    $this->get($url)
        ->assertOk()
        ->assertSee($page->title);
});

test('the sign in a signed out deep link offers is the same whatever page id was asked for', function (): void {
    $owner = deepLinkMember();
    $page = deepLinkPage($owner, 'Opaque deep link page '.Str::random(8));
    $lookups = [];
    DB::listen(function ($query) use (&$lookups): void {
        if (preg_match('/from ["`]?page(?:s|_versions|_shares)["`]?/', $query->sql) === 1) {
            $lookups[] = $query->sql;
        }
    });

    foreach ([
        (string) $page->id,
        (string) Str::uuid(),
        'not-a-uuid',
    ] as $candidate) {
        $path = '/pages/'.$candidate;

        $this->get($path)
            ->assertRedirect(route('bfc.managed.login', ['intended' => $path]))
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    expect($lookups)->toBe([]);
});

test('signing in through a page deep link still refuses a reader the page was never shared with', function (): void {
    $owner = deepLinkMember();
    $stranger = deepLinkMember(['role' => UserRole::Member->value, 'managed_membership_role' => UserRole::Member->value]);
    $page = deepLinkPage($owner, 'Unshared deep link page '.Str::random(8));
    $url = route('pages.show', ['page' => $page], absolute: false);
    fakeScalpelsHandoff($stranger);

    $handoff = $this->get($url)->assertRedirect(route('bfc.managed.login', ['intended' => $url]));
    $handoff = $this->get((string) $handoff->headers->get('Location'));

    $this->get(route('bfc.managed.callback', [
        'state' => deepLinkAuthorizationState((string) $handoff->headers->get('Location')),
        'code' => 'deep-link-code',
    ]))->assertRedirect($url);

    $refused = $this->get($url)->assertNotFound();

    expect($refused->getContent())->toBe('')
        ->and($refused->getContent())->not->toContain($page->title);
});

test('a page deep link handoff completed by a different browser is refused', function (): void {
    $owner = deepLinkMember();
    $page = deepLinkPage($owner, 'Bound deep link page '.Str::random(8));
    $url = route('pages.show', ['page' => $page], absolute: false);
    fakeScalpelsHandoff($owner);

    $handoff = $this->get($url)->assertRedirect(route('bfc.managed.login', ['intended' => $url]));
    $handoff = $this->get((string) $handoff->headers->get('Location'));
    $state = deepLinkAuthorizationState((string) $handoff->headers->get('Location'));

    // The nonce that binds the handoff lives in the initiating browser's
    // session, so a second browser arriving with the same state has none.
    $this->flushSession();

    $this->get(route('bfc.managed.callback', ['state' => $state, 'code' => 'deep-link-code']))
        ->assertNotFound();

    $this->get($url)->assertRedirect(route('bfc.managed.login', ['intended' => $url]));
});

test('a signed out page library link comes back to the library once managed sign in completes', function (): void {
    $owner = deepLinkMember();
    $page = deepLinkPage($owner, 'Library deep link page '.Str::random(8));
    $url = route('pages.index', absolute: false);
    fakeScalpelsHandoff($owner);
    $lookups = [];
    DB::listen(function ($query) use (&$lookups): void {
        if (preg_match('/from ["`]?page(?:s|_versions|_shares)["`]?/', $query->sql) === 1) {
            $lookups[] = $query->sql;
        }
    });

    $this->get($url)
        ->assertRedirect(route('bfc.managed.login', ['intended' => $url]))
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

    expect($lookups)->toBe([]);

    $handoff = $this->get(route('bfc.managed.login', ['intended' => $url]));
    $handoff->assertRedirectContains(deepLinkIssuer().ManagedAuthClient::AUTHORIZE_PATH)
        ->assertSessionHas(ManagedHandoff::SESSION_INTENDED_KEY, $url);

    $this->get(route('bfc.managed.callback', [
        'state' => deepLinkAuthorizationState((string) $handoff->headers->get('Location')),
        'code' => 'deep-link-code',
    ]))->assertRedirect($url);

    $this->get($url)
        ->assertOk()
        ->assertSee($page->title);
});

test('a signed out page export link comes back to that same export once managed sign in completes', function (): void {
    $owner = deepLinkMember();
    $page = deepLinkPage($owner, 'Export deep link page '.Str::random(8));
    $url = route('pages.export', ['page' => $page], absolute: false);
    fakeScalpelsHandoff($owner);
    $lookups = [];
    DB::listen(function ($query) use (&$lookups): void {
        if (preg_match('/from ["`]?page(?:s|_versions|_shares)["`]?/', $query->sql) === 1) {
            $lookups[] = $query->sql;
        }
    });

    // The export's sign-in offer is the same whatever page id was asked for,
    // and it is made before the id is even validated.
    foreach ([
        (string) $page->id,
        (string) Str::uuid(),
        'not-a-uuid',
    ] as $candidate) {
        $path = '/pages/'.$candidate.'/export';

        $this->get($path)
            ->assertRedirect(route('bfc.managed.login', ['intended' => $path]))
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    expect($lookups)->toBe([]);

    $handoff = $this->get(route('bfc.managed.login', ['intended' => $url]));
    $handoff->assertRedirectContains(deepLinkIssuer().ManagedAuthClient::AUTHORIZE_PATH)
        ->assertSessionHas(ManagedHandoff::SESSION_INTENDED_KEY, $url);

    $this->get(route('bfc.managed.callback', [
        'state' => deepLinkAuthorizationState((string) $handoff->headers->get('Location')),
        'code' => 'deep-link-code',
    ]))->assertRedirect($url);

    $this->get($url)
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="page-'.$page->id.'.html"');
});

test('a signed out export link still refuses a signed in reader who does not own the page', function (): void {
    $owner = deepLinkMember();
    $stranger = deepLinkMember(['role' => UserRole::Member->value, 'managed_membership_role' => UserRole::Member->value]);
    $page = deepLinkPage($owner, 'Unowned export page '.Str::random(8));
    $url = route('pages.export', ['page' => $page], absolute: false);
    fakeScalpelsHandoff($stranger);

    $handoff = $this->get($url)->assertRedirect(route('bfc.managed.login', ['intended' => $url]));
    $handoff = $this->get((string) $handoff->headers->get('Location'));

    $this->get(route('bfc.managed.callback', [
        'state' => deepLinkAuthorizationState((string) $handoff->headers->get('Location')),
        'code' => 'deep-link-code',
    ]))->assertRedirect($url);

    // Still the identical empty 404 for every id, now that somebody is signed in.
    foreach ([
        $url,
        '/pages/'.Str::uuid().'/export',
        '/pages/not-a-uuid/export',
    ] as $path) {
        $refused = $this->get($path)->assertNotFound();

        expect($refused->getContent())->toBe('')
            ->and($refused->getContent())->not->toContain($page->title);
    }
});

test('a managed session the authority no longer admits is sent to sign in rather than refused', function (): void {
    $owner = deepLinkMember();
    $page = deepLinkPage($owner, 'Unadmitted session page '.Str::random(8));

    // The authority binds a session to one Scalpels connection. A member whose
    // binding no longer matches is not an authenticated human here, so the gate
    // ends the session and asks for sign-in — and that answer now reaches the
    // browser instead of collapsing into a not-found.
    $unbound = deepLinkMember([
        'role' => UserRole::Member->value,
        'managed_membership_role' => UserRole::Member->value,
        'scalpels_connection_id' => 'some-other-connection',
    ]);
    $version = $page->latestVersion()->firstOrFail();
    $lookups = [];
    DB::listen(function ($query) use (&$lookups): void {
        if (preg_match('/from ["`]?page(?:s|_versions|_shares)["`]?/', $query->sql) === 1) {
            $lookups[] = $query->sql;
        }
    });

    foreach ([
        route('pages.show', ['page' => $page], absolute: false),
        route('pages.versions.show', ['page' => $page, 'version' => $version], absolute: false),
        route('pages.index', absolute: false),
        route('pages.export', ['page' => $page], absolute: false),
    ] as $path) {
        $response = $this->actingAsVersioned($unbound)->get($path)
            ->assertRedirect(route('bfc.managed.login', ['intended' => $path]))
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

        expect($response->getContent())->not->toContain($page->title);
    }

    expect($lookups)->toBe([]);
});
