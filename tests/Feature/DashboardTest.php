<?php

use ArtisanBuild\BuiltForCloud\UserRole;

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('d', 32))]);
});

test('guests are redirected to the package login page', function (): void {
    $this->get(route('dashboard'))->assertRedirect(route('bfc.login'));
});

test('every active package role can visit the dashboard', function (UserRole $role): void {
    $this->actingAsVersioned(capstanUser(['role' => $role->value]))
        ->get(route('dashboard'))
        ->assertOk();
})->with(UserRole::cases());
