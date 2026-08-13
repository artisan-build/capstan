<?php

use App\Models\User;
use ArtisanBuild\BuiltForCloud\Testing\ContractAssertions;

uses(ContractAssertions::class);

it('conforms to the Built for Cloud catalog contract', function (): void {
    // The shipped assertion covers /bfc/meta, the ownership + onboarding auth
    // boundaries, and the api_tokens model shape. None of it touches the
    // auth-foundation migrations Capstan opts out of, so it runs unmodified.
    $this->assertBuiltForCloudContract();
});

it('reports Capstan as the product name', function (): void {
    $this->getJson('/bfc/meta')->assertOk()->assertJsonPath('product', 'Capstan');
});

it('resolves the auth user provider to the Capstan user model', function (): void {
    expect(config('auth.providers.users.model'))->toBe(User::class);
});
