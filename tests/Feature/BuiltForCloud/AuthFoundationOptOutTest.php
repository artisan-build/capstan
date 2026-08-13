<?php

use App\Enums\OrgRole;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

it('keeps Capstan invitations table after the Built for Cloud migrations run', function (): void {
    expect(Schema::hasTable('invitations'))->toBeTrue();

    // Capstan's columns, not the package's (which ships a uuid id + token).
    expect(Schema::hasColumns('invitations', ['id', 'code', 'role', 'issued_by', 'used_by']))->toBeTrue()
        ->and(Schema::hasColumn('invitations', 'token'))->toBeFalse();
});

it('creates the Built for Cloud ownership tables', function (): void {
    foreach (['api_tokens', 'ownership_claims', 'ownership', 'onboarding_tokens'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Expected the {$table} table to exist.");
    }
});

it('does not add an is_admin column to users', function (): void {
    expect(Schema::hasColumn('users', 'is_admin'))->toBeFalse();
});

it('derives is_admin from the org role', function (OrgRole $role, bool $expected): void {
    $user = User::factory()->create(['org_role' => $role]);

    expect($user->is_admin)->toBe($expected)
        ->and($user->getAttribute('is_admin'))->toBe($expected);
})->with([
    [OrgRole::Owner, true],
    [OrgRole::Admin, true],
    [OrgRole::Member, false],
]);

it('lets the Built for Cloud admin middleware gate on the org role', function (OrgRole $role, int $status): void {
    Route::middleware(['web', 'bfc.admin'])->get('/test-bfc-admin', fn () => response()->noContent());

    $this->actingAs(User::factory()->create(['org_role' => $role]))
        ->get('/test-bfc-admin')
        ->assertStatus($status);
})->with([
    [OrgRole::Owner, 204],
    [OrgRole::Admin, 204],
    [OrgRole::Member, 403],
]);

it('never persists the derived is_admin attribute', function (): void {
    $user = User::factory()->create(['org_role' => OrgRole::Owner]);

    expect($user->is_admin)->toBeTrue()
        ->and($user->getAttributes())->not->toHaveKey('is_admin')
        ->and(array_key_exists('is_admin', $user->fresh()->getAttributes()))->toBeFalse();
});
