<?php

namespace Tests;

use App\Auth\CapstanCredentialDeclaration;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function actingAsVersioned(User $user, ?string $guard = null): static
    {
        $user->refresh();

        return $this->actingAs($user, $guard)->withSession([
            StandaloneAccess::SESSION_VERSION_KEY => $user->auth_session_version,
        ]);
    }

    /**
     * Existing protocol tests call withToken(); bound credentials also require
     * the independently transported actor header.
     */
    public function withToken(#[\SensitiveParameter] string $token, string $type = 'Bearer')
    {
        $credential = Credential::query()->where('secret_hash', hash('sha256', $token))->first();

        if ($credential?->user_id !== null) {
            $this->withHeader(CapstanCredentialDeclaration::ACTOR_HEADER, $credential->user_id);
        }

        return parent::withToken($token, $type);
    }
}
