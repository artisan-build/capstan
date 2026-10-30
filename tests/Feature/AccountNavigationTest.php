<?php

use ArtisanBuild\BuiltForCloud\AuthorityMode;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Support\Facades\DB;

function accountNavigationTargets(string $html): array
{
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $targets = [];

    expect($xpath->query('//*[@data-testid="account-navigation"]')->length)->toBe(0);

    $logout = $xpath->query('//*[@data-testid="bfc-user-menu-logout"]');
    expect($logout->length)->toBe(1);
    $targets['bfc-user-menu-logout'] = $logout->item(0)?->getAttribute('action');

    return $targets;
}

test('the sidebar carries no Account link, and the settings page and logout still work in standalone and managed modes', function (string $mode): void {
    config(['built-for-cloud.managed.client_secret' => str_repeat('n', 64)]);
    $attributes = ['role' => UserRole::Owner->value];

    if ($mode === AuthorityMode::Managed->value) {
        DB::table('bfc_authority')->where('key', InstallationAuthority::KEY)->update([
            'mode' => AuthorityMode::Managed->value,
            'generation' => 2,
            'issuer' => 'https://navigation-scalpels.test',
            'connection_id' => 'navigation-connection',
            'organization_id' => 'navigation-organization',
            'installation_id' => 'navigation-installation',
            'authority_base_url' => 'https://navigation-scalpels.test',
            'managed_connection_status' => 'active',
            'managed_connection_generation' => 2,
            'managed_connection_roster_version' => 1,
            'managed_connection_response_sequence' => 1,
        ]);
        $attributes += [
            'scalpels_issuer' => 'https://navigation-scalpels.test',
            'scalpels_connection_id' => 'navigation-connection',
            'scalpels_id' => 'navigation-user',
            'managed_membership_status' => 'active',
            'managed_membership_role' => UserRole::Owner->value,
            'managed_membership_generation' => 2,
            'managed_membership_roster_version' => 1,
            'managed_membership_response_sequence' => 1,
            'managed_membership_responded_at' => now()->toAtomString(),
            'membership_confirmed_at' => now(),
        ];
    }

    $user = capstanUser($attributes);
    $dashboard = $this->actingAsVersioned($user)->get(route('bfc.dashboard'))->assertOk();

    expect(accountNavigationTargets($dashboard->content()))->toBe([
        'bfc-user-menu-logout' => route('bfc.ui.logout'),
    ]);
    $this->get(route('bfc.ui.home'))->assertOk();
    $this->post(route('bfc.ui.logout'))->assertRedirect('/');
})->with([
    AuthorityMode::Standalone->value,
    AuthorityMode::Managed->value,
]);
