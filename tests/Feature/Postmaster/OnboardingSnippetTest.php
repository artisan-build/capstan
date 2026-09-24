<?php

use App\Auth\CapstanCredentialDeclaration;
use App\Enums\SpokeLiveness;
use App\Enums\SpokeMapStatus;
use App\Livewire\Postmaster\SpokeMap;
use App\Models\Spoke;
use App\Postmaster\OnboardingSnippet;
use App\Support\ServerIdentity;
use ArtisanBuild\BuiltForCloud\Contracts\IdentityContext;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;

const ONBOARDING_SERVER_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('o', 32)),
        'app.name' => 'Capstan',
        'app.url' => 'https://capstan.example',
        'capstan.features.postmaster' => true,
        'capstan.postmaster.server_id' => ONBOARDING_SERVER_ID,
    ]);
    Feature::flushCache();
    RateLimiter::clear('postmaster-onboarding:127.0.0.1');
    app()->forgetInstance(ServerIdentity::class);
    request()->setLaravelSession(resolve('session')->driver());
});

function onboardingSnippetFor(object $test, User $user): string
{
    $test->actingAs($user);
    $request = Request::create('/postmaster', 'GET');
    $request->setUserResolver(static fn (): User => $user);
    $request->setLaravelSession(resolve('session')->driver());

    return resolve(OnboardingSnippet::class)->generate($request, (string) $user->getKey());
}

test('the snippet starts the fixed package device flow without disclosing durable secrets', function (): void {
    $operator = capstanUser();
    $existing = capstanBoundBearer($operator, CapstanCredentialDeclaration::POSTMASTER_POLL);
    $snippet = onboardingSnippetFor($this, $operator);
    $authorization = DB::table('credential_authorizations')->sole();

    preg_match("/^CAPSTAN_DEVICE_CODE='([^']+)'$/m", $snippet, $device);
    preg_match("/^CAPSTAN_USER_CODE='([^']+)'$/m", $snippet, $user);

    expect($device)->toHaveCount(2)
        ->and($user)->toHaveCount(2)
        ->and($snippet)->toContain("CAPSTAN_ACTOR_ID='".$operator->getKey()."'")
        ->toContain("CAPSTAN_TOKEN_URL='https://capstan.example/bfc/device/token'")
        ->toContain('CAPSTAN_VERIFY_URL='.escapeshellarg(url('/bfc/device')))
        ->toContain("CAPSTAN_POLL_URL='https://capstan.example/api/v1/poll'")
        ->not->toContain($existing['token'])
        ->not->toContain((string) config('app.key'))
        ->and($authorization->app_purpose)->toBe(CapstanCredentialDeclaration::POSTMASTER_POLL)
        ->and($authorization->initiating_user_id)->toBe((string) $operator->getKey())
        ->and($authorization->device_code_hash)->toBe(hash('sha256', $device[1]))
        ->and($authorization->user_code_hash)->toBe(hash('sha256', $user[1]))
        ->and($authorization->status)->toBe('pending')
        ->and(DB::table('credentials')->count())->toBe(1);
});

test('the generated package installer is valid shell with safely quoted install values', function (): void {
    config([
        'app.name' => 'Capstan $(touch /tmp/nope) O\'Reilly',
        'app.url' => 'https://example.test/a path/$(not-a-command)/',
    ]);
    $snippet = onboardingSnippetFor($this, capstanUser());
    $syntax = new Process(['/bin/sh', '-n']);
    $syntax->setInput($snippet);
    $syntax->run();

    expect($syntax->isSuccessful())->toBeTrue($syntax->getErrorOutput())
        ->and($snippet)->toContain(escapeshellarg((string) config('app.name')))
        ->toContain(escapeshellarg('https://example.test/a path/$(not-a-command)/api/v1/poll'))
        ->not->toContain('https://example.test/a path/$(not-a-command)//api/v1/poll');
});

test('every active package role can see and generate onboarding', function (UserRole $role): void {
    $operator = capstanUser(['role' => $role->value]);
    $this->actingAsVersioned($operator)
        ->get(route('postmaster.map'))
        ->assertOk()
        ->assertSee('data-testid="postmaster-onboarding"', false);

    expect(onboardingSnippetFor($this, $operator))->toContain("CAPSTAN_ACTOR_ID='".$operator->getKey()."'")
        ->and(DB::table('credential_authorizations')->count())->toBe(1);
})->with(UserRole::cases());

test('onboarding requires authentication and an enabled feature', function (): void {
    $this->get(route('postmaster.map'))->assertRedirect(route('bfc.login'));
    expect(DB::table('credential_authorizations')->count())->toBe(0);

    $operator = capstanUser();
    config(['capstan.features.postmaster' => false]);
    Feature::flushCache();

    $this->actingAsVersioned($operator)->get(route('postmaster.map'))->assertNotFound();
    expect(DB::table('credential_authorizations')->count())->toBe(0);
});

test('an empty app url cannot start a device authorization', function (): void {
    config(['app.url' => '']);

    expect(fn (): string => onboardingSnippetFor($this, capstanUser()))
        ->toThrow(RuntimeException::class);
    expect(DB::table('credential_authorizations')->count())->toBe(0);
});

test('onboarding generation is limited to fifteen attempts per minute per ip', function (): void {
    $operator = capstanUser();
    $this->actingAsVersioned($operator);
    request()->setUserResolver(static fn (): User => $operator);
    $component = resolve(SpokeMap::class);

    foreach (range(1, 15) as $attempt) {
        RateLimiter::hit('postmaster-onboarding:127.0.0.1', 60);
    }

    try {
        $component->generateOnboardingSnippet(
            resolve(OnboardingSnippet::class),
            resolve(IdentityContext::class),
        );
        $this->fail('The sixteenth onboarding attempt should be rate limited.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(429);
    }

    expect(DB::table('credential_authorizations')->count())->toBe(0);
});

test('the first poll is pending and the first passing probe turns the same spoke green', function (): void {
    $operator = capstanUser();
    $token = capstanBoundBearer($operator, CapstanCredentialDeclaration::POSTMASTER_POLL)['token'];
    $first = $this->withToken($token)->postJson(route('api.postmaster.poll'), [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk();
    $spoke = Spoke::query()->sole();

    expect($spoke->probe_status)->toBe(SpokeLiveness::Unknown)
        ->and(Livewire::actingAs($operator)->test(SpokeMap::class)->viewData('spokes')->sole()['status'])
        ->toBe(SpokeMapStatus::Pending);

    $challenge = $first->json('probe_challenge');
    $this->withToken($token)->postJson(route('api.postmaster.poll'), [
        'presence' => ['ready_inboxes' => []],
        'probe_response' => [
            'probe_id' => $challenge['probe_id'],
            'digest' => hash('sha256', $challenge['nonce']),
        ],
    ])->assertOk();

    expect($spoke->refresh()->probe_status)->toBe(SpokeLiveness::Green)
        ->and(Livewire::actingAs($operator)->test(SpokeMap::class)->viewData('spokes')->sole()['status'])
        ->toBe(SpokeMapStatus::Green);
});
