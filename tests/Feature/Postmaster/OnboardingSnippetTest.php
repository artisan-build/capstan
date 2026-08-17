<?php

use App\Enums\OrgRole;
use App\Enums\SpokeLiveness;
use App\Enums\SpokeMapStatus;
use App\Livewire\Postmaster\SpokeMap;
use App\Models\DeviceCode;
use App\Models\Spoke;
use App\Models\User;
use App\Postmaster\OnboardingSnippet;
use App\Support\ServerIdentity;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Symfony\Component\Process\Process;

const ONBOARDING_SERVER_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
const OTHER_ONBOARDING_SERVER_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAW';

beforeEach(function (): void {
    config([
        'app.name' => 'Capstan',
        'app.url' => 'https://capstan.example',
        'capstan.features.postmaster' => true,
        'capstan.postmaster.server_id' => ONBOARDING_SERVER_ID,
    ]);
    Feature::flushCache();
    app()->forgetInstance(ServerIdentity::class);
});

test('the snippet is install specific and contains only an expiring device grant', function (): void {
    $snippet = app(OnboardingSnippet::class)->generate();
    $device = DeviceCode::query()->sole();

    expect($snippet)
        ->toContain(escapeshellarg(ONBOARDING_SERVER_ID))
        ->toContain(escapeshellarg('https://capstan.example/api/v1/poll'))
        ->toContain(escapeshellarg('https://capstan.example/cli/device?user_code='.$device->user_code))
        ->not->toMatch('/\b\d+\|[A-Za-z0-9]{40,}\b/')
        ->and(DB::table('personal_access_tokens')->count())->toBe(0)
        ->and($device->expires_at->diffInSeconds(now()))->toBeLessThanOrEqual(DeviceCode::LIFETIME_SECONDS)
        ->and($device->expires_at->isFuture())->toBeTrue();

    preg_match("/^CAPSTAN_DEVICE_CODE='([A-Za-z0-9]+)'$/m", $snippet, $matches);
    expect($matches)->toHaveCount(2)
        ->and($device->device_code_hash)->toBe(DeviceCode::hash($matches[1]));

    config([
        'app.url' => 'https://another.example/base',
        'capstan.postmaster.server_id' => OTHER_ONBOARDING_SERVER_ID,
    ]);
    app()->forgetInstance(ServerIdentity::class);
    $otherSnippet = app(OnboardingSnippet::class)->generate();

    expect($otherSnippet)
        ->not->toBe($snippet)
        ->toContain(escapeshellarg(OTHER_ONBOARDING_SERVER_ID))
        ->toContain(escapeshellarg('https://another.example/base/api/v1/poll'));
});

test('the snippet is valid shell with quoted install values and a minute cron', function (): void {
    config([
        'app.name' => 'Capstan $(touch /tmp/nope) O\'Reilly',
        'app.url' => 'https://example.test/a path/$(not-a-command)',
    ]);
    $snippet = app(OnboardingSnippet::class)->generate();
    $syntax = new Process(['/bin/sh', '-n']);
    $syntax->setInput($snippet);
    $syntax->run();

    $pollScriptPath = tempnam(sys_get_temp_dir(), 'capstan-poll-');
    expect($pollScriptPath)->toBeString();
    $writerNeedle = "\nprintf '%s\\n' '#!/bin/sh'";
    $writerStart = strpos($snippet, $writerNeedle);
    $writerSuffix = ' > "$CAPSTAN_POLL_SCRIPT"';
    expect($writerStart)->toBeInt();
    $writerStart++;
    $writerEnd = strpos($snippet, $writerSuffix, $writerStart);
    expect($writerEnd)->toBeInt();
    $pollScriptWriter = substr($snippet, $writerStart, $writerEnd + strlen($writerSuffix) - $writerStart);
    $pollSyntax = new Process(['/bin/sh', '-c', $pollScriptWriter.' && /bin/sh -n "$CAPSTAN_POLL_SCRIPT"']);
    $pollSyntax->setEnv(['CAPSTAN_POLL_SCRIPT' => $pollScriptPath]);
    $pollSyntax->run();

    expect($syntax->isSuccessful())->toBeTrue($syntax->getErrorOutput())
        ->and($pollSyntax->isSuccessful())->toBeTrue($pollSyntax->getErrorOutput())
        ->and($snippet)->toContain(escapeshellarg((string) config('app.name')))
        ->and($snippet)->toContain(escapeshellarg('https://example.test/a path/$(not-a-command)/api/v1/poll'))
        ->and($snippet)->toMatch('/CAPSTAN_CRON_LINE=.*\* \* \* \* \*/')
        ->and($snippet)->toContain(escapeshellarg(
            'https://example.test/a path/$(not-a-command)/cli/device?user_code='.DeviceCode::query()->sole()->user_code,
        ));

    unlink($pollScriptPath);
});

test('owners and admins can generate the onboarding panel', function (OrgRole $role): void {
    $operator = User::factory()->create(['org_role' => $role]);

    $component = Livewire::actingAs($operator)->test(SpokeMap::class);

    $component->assertOk()
        ->assertViewHas('canOnboard', true)
        ->assertSeeHtml('data-onboarding-panel')
        ->assertSeeHtml('data-onboarding-snippet');
    expect($component->get('onboardingSnippet'))->toBeString()
        ->and(DeviceCode::query()->count())->toBe(1)
        ->and(DB::table('personal_access_tokens')->count())->toBe(0);
})->with([
    'owner' => OrgRole::Owner,
    'admin' => OrgRole::Admin,
]);

test('members cannot retrieve or generate a snippet', function (): void {
    $member = User::factory()->create(['org_role' => OrgRole::Member]);

    $component = Livewire::actingAs($member)->test(SpokeMap::class);

    $component->assertOk()
        ->assertViewHas('canOnboard', false)
        ->assertDontSeeHtml('data-onboarding-panel')
        ->assertSet('onboardingSnippet', null)
        ->call('refreshOnboardingSnippet')
        ->assertForbidden();
    expect(DeviceCode::query()->count())->toBe(0);
});

test('onboarding requires authentication', function (): void {
    $this->get(route('postmaster.map'))
        ->assertRedirect(route('login'));

    expect(DeviceCode::query()->count())->toBe(0);
});

test('a disabled feature rejects initial and existing component requests without issuing codes', function (): void {
    $owner = User::factory()->create(['org_role' => OrgRole::Owner]);
    config(['capstan.features.postmaster' => false]);
    Feature::flushCache();

    $this->actingAs($owner)->get(route('postmaster.map'))->assertNotFound();
    expect(DeviceCode::query()->count())->toBe(0);

    config(['capstan.features.postmaster' => true]);
    Feature::flushCache();
    $component = Livewire::actingAs($owner)->test(SpokeMap::class);
    expect(DeviceCode::query()->count())->toBe(1);

    config(['capstan.features.postmaster' => false]);
    Feature::flushCache();
    $component->call('refreshOnboardingSnippet')->assertNotFound();
    expect(DeviceCode::query()->count())->toBe(1);
});

test('a role change is enforced before regenerating a snippet', function (): void {
    $admin = User::factory()->create(['org_role' => OrgRole::Admin]);
    $component = Livewire::actingAs($admin)->test(SpokeMap::class);
    $admin->forceFill(['org_role' => OrgRole::Member])->save();

    $component->call('refreshOnboardingSnippet')->assertForbidden();

    expect(DeviceCode::query()->count())->toBe(1);
});

test('the first poll is pending and the first passing probe turns the same spoke green', function (): void {
    $owner = User::factory()->create(['org_role' => OrgRole::Owner]);
    $token = $owner->createToken('capstan-cli')->plainTextToken;
    $first = $this->withToken($token)->postJson(route('api.postmaster.poll'), [
        'presence' => ['ready_inboxes' => []],
    ])->assertOk();
    $spoke = Spoke::query()->sole();

    expect($spoke->probe_status)->toBe(SpokeLiveness::Unknown)
        ->and(Spoke::query()->count())->toBe(1);
    $pending = Livewire::actingAs($owner)->test(SpokeMap::class);
    expect($pending->viewData('spokes')->sole()['status'])->toBe(SpokeMapStatus::Pending);
    $pending->assertSeeHtml('data-status="pending"');

    $challenge = $first->json('probe_challenge');
    $this->withToken($token)->postJson(route('api.postmaster.poll'), [
        'presence' => ['ready_inboxes' => []],
        'probe_response' => [
            'probe_id' => $challenge['probe_id'],
            'digest' => hash('sha256', $challenge['nonce']),
        ],
    ])->assertOk();

    expect(Spoke::query()->count())->toBe(1)
        ->and($spoke->refresh()->probe_status)->toBe(SpokeLiveness::Green);
    $green = Livewire::actingAs($owner)->test(SpokeMap::class);
    expect($green->viewData('spokes')->sole()['status'])->toBe(SpokeMapStatus::Green);
    $green->assertSeeHtml('data-status="green"');
});
