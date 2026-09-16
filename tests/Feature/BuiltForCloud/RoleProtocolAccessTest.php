<?php

use App\Auth\CapstanCredentialDeclaration;
use App\Models\Artifact;
use App\Models\Spoke;
use ArtisanBuild\BuiltForCloud\Hmac\SigningRootLifecycle;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

test('every package role can complete bound artifact ingest and Postmaster poll', function (UserRole $role): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('r', 32)),
        'capstan.features.artifacts' => true,
        'capstan.features.postmaster' => true,
        'capstan.postmaster.server_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
    ]);
    Feature::flushCache();
    Storage::fake();
    app(SigningRootLifecycle::class)->provision();
    $user = capstanUser(['role' => $role->value]);

    $this->withHeaders(capstanBearerHeaders($user, CapstanCredentialDeclaration::ARTIFACT_INGEST))
        ->postJson('/api/v1/artifacts', [
            'content' => '<html><body>'.$role->value.' artifact</body></html>',
            'content_type' => 'text/html',
        ])
        ->assertCreated()
        ->assertJsonPath('artifact.actor_id', (string) $user->getKey());

    $this->withHeaders(capstanBearerHeaders($user, CapstanCredentialDeclaration::POSTMASTER_POLL))
        ->postJson(route('api.postmaster.poll'), [
            'presence' => ['ready_inboxes' => []],
        ])
        ->assertOk();

    expect(Artifact::query()->sole()->actor_id)->toBe((string) $user->getKey())
        ->and(Spoke::query()->sole()->actor_id)->toBe((string) $user->getKey());
})->with(UserRole::cases());
