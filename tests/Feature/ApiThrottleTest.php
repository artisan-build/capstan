<?php

use App\Auth\CapstanCredentialDeclaration;
use App\Http\Middleware\AuthenticateBoundCredential;
use App\Models\Artifact;
use App\Models\Inbox;
use App\Models\Spoke;
use ArtisanBuild\BuiltForCloud\ClientIdentity;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Feature;

test('product api routes authenticate their fixed purpose before sharing the api throttle', function (): void {
    $routes = [
        '/api/v1/artifacts' => CapstanCredentialDeclaration::ARTIFACT_INGEST,
        '/api/v1/poll' => CapstanCredentialDeclaration::POSTMASTER_POLL,
    ];

    foreach ($routes as $uri => $appPurpose) {
        $route = Route::getRoutes()->match(Request::create($uri, 'POST'));
        $middleware = resolve(Router::class)->gatherRouteMiddleware($route);
        $authentication = AuthenticateBoundCredential::class.':'.$appPurpose;
        $throttle = ThrottleRequests::class.':api';

        expect($middleware)->toContain($authentication, $throttle)
            ->and(array_search($authentication, $middleware, true))
            ->toBeLessThan(array_search($throttle, $middleware, true));
    }
});

test('subject mismatch cannot consume the shared actor api throttle', function (): void {
    config(['capstan.features.artifacts' => true]);
    Feature::flushCache();
    $actor = capstanUser();
    $other = capstanUser();
    $artifact = capstanBoundBearer($actor, CapstanCredentialDeclaration::ARTIFACT_INGEST);
    $poll = capstanBoundBearer($actor, CapstanCredentialDeclaration::POSTMASTER_POLL);
    $artifactHeaders = [
        'Authorization' => 'Bearer '.$artifact['token'],
        CapstanCredentialDeclaration::ACTOR_HEADER => (string) $actor->getKey(),
        ClientIdentity::HEADER => 'capstan-throttle-test/1.0',
    ];

    $this->withHeaders([
        ...$artifactHeaders,
        CapstanCredentialDeclaration::ACTOR_HEADER => (string) $other->getKey(),
    ])->postJson('/api/v1/artifacts', [])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated')
        ->assertHeaderMissing('X-RateLimit-Limit')
        ->assertHeaderMissing('X-RateLimit-Remaining');

    expect($artifact['credential']->refresh()->last_used_at)->toBeNull();

    foreach (range(1, 60) as $attempt) {
        $this->withHeaders($artifactHeaders)
            ->postJson('/api/v1/artifacts', [])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertHeader('X-RateLimit-Limit', '60')
            ->assertHeader('X-RateLimit-Remaining', (string) (60 - $attempt));
    }

    $usedArtifactCredential = $artifact['credential']->refresh();
    expect($usedArtifactCredential->last_used_at)->not->toBeNull()
        ->and($usedArtifactCredential->client_identity)->toBe('capstan-throttle-test/1.0');

    $this->withHeaders([
        'Authorization' => 'Bearer '.$poll['token'],
        CapstanCredentialDeclaration::ACTOR_HEADER => (string) $actor->getKey(),
        ClientIdentity::HEADER => 'capstan-throttle-test/1.0',
    ])->postJson('/api/v1/poll', [
        'presence' => ['ready_inboxes' => ['throttled']],
    ])->assertTooManyRequests()
        ->assertHeader('X-RateLimit-Limit', '60')
        ->assertHeader('X-RateLimit-Remaining', '0')
        ->assertHeader('Retry-After');

    $usedPollCredential = $poll['credential']->refresh();
    expect($usedPollCredential->last_used_at)->not->toBeNull()
        ->and($usedPollCredential->client_identity)->toBe('capstan-throttle-test/1.0')
        ->and(Artifact::query()->count())->toBe(0)
        ->and(Spoke::query()->count())->toBe(0)
        ->and(Inbox::query()->count())->toBe(0);
});
