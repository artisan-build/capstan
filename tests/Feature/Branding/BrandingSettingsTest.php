<?php

use App\Models\BrandAsset;
use App\Models\BrandKit;
use App\Models\BrandVersion;
use App\Support\BrandKitService;
use App\Support\BrandPageRenderer;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('u', 32)),
        'app.url' => 'https://app.brand-ui.test',
        'capstan.features.artifacts' => true,
        'capstan.artifacts.render_origin' => 'https://render.brand-ui.test',
    ]);
    Feature::flushCache();
    Storage::fake();
});

function brandingSettingsPng(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
}

test('owner and admin see gated branding navigation and settings built from durable data', function (UserRole $role): void {
    $actor = capstanUser(['role' => $role->value]);
    $name = 'Settings brand '.Str::random(10);
    resolve(BrandKitService::class)->updateDraft($actor, ['display_name' => $name]);

    $response = $this->actingAsVersioned($actor)->get(route('branding.show'))
        ->assertOk()
        ->assertSee('data-testid="branding-settings"', false)
        ->assertSee('data-testid="branding-current"', false)
        ->assertSee('data-testid="branding-draft-form"', false)
        ->assertSee('data-testid="branding-assets"', false)
        ->assertSee('data-testid="branding-preview"', false)
        ->assertSee('data-testid="branding-apply"', false)
        ->assertSee('data-testid="branding-history"', false)
        ->assertSee($name)
        ->assertSee('Font uploads are not supported.');
    expect($response->content())->toContain('data-testid="branding-navigation"', 'https://render.brand-ui.test/brand-previews/')
        ->not->toContain('<style data-capstan-brand="draft">');
})->with([UserRole::Owner, UserRole::Admin]);

test('member guest inactive offboarded feature-off and render-host requests are refused before brand lookup or mutation', function (): void {
    $member = capstanUser(['role' => UserRole::Member->value]);
    $inactive = capstanUser(['role' => UserRole::Admin->value, 'status' => 'inactive']);
    $offboarded = capstanUser(['role' => UserRole::Admin->value]);
    OffboardedSubject::query()->create([
        'subject_type' => SubjectType::UserPrincipal,
        'subject_ref' => 'capstan-user:'.$offboarded->getKey(),
        'user_id' => (string) $offboarded->getKey(),
        'offboarded_at' => now(),
    ]);

    $responses = [
        $this->get(route('branding.show')),
        $this->actingAsVersioned($member)->get(route('branding.show')),
        $this->actingAsVersioned($inactive)->get(route('branding.show')),
        $this->actingAsVersioned($offboarded)->get(route('branding.show')),
        $this->actingAsVersioned($member)->patch(route('branding.draft.update'), ['display_name' => 'Denied']),
        $this->actingAsVersioned($member)->post(route('branding.assets.store'), ['slot' => 'mark']),
        $this->actingAsVersioned($member)->post(route('branding.apply')),
        $this->actingAsVersioned($member)->post(route('branding.versions.revert', ['version' => 1])),
        $this->actingAsVersioned(capstanUser(['role' => UserRole::Admin->value]))
            ->get('https://render.brand-ui.test/branding'),
    ];
    foreach ($responses as $response) {
        expect($response->getStatusCode())->toBeIn([302, 403, 404]);
        if ($response->getStatusCode() === 404) {
            $response->assertContent('');
        }
    }

    config(['capstan.features.artifacts' => false]);
    Feature::flushCache();
    $admin = capstanUser(['role' => UserRole::Admin->value]);
    $this->actingAsVersioned($admin)->get('https://app.brand-ui.test'.route('branding.show', [], false))->assertNotFound();
    $this->actingAsVersioned($admin)->get('https://app.brand-ui.test'.route('bfc.dashboard', [], false))->assertOk()->assertDontSee('data-testid="branding-navigation"', false);
    expect(BrandKit::query()->count())->toBe(0)
        ->and(BrandVersion::query()->count())->toBe(0)
        ->and(BrandAsset::query()->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBe([]);
});

test('settings save validated draft values preserve invalid input and never execute chrome on the app origin', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $name = 'Human draft '.Str::random(8);
    $headerMarker = 'header-'.Str::random(10);
    $cssMarker = 'css-'.mb_strtolower(Str::random(10));

    $this->actingAsVersioned($owner)->patch(route('branding.draft.update'), [
        'display_name' => $name,
        'colours' => ['light' => ['primary' => '#123abc']],
        'fonts' => ['heading' => ['type' => 'stack', 'value' => 'system-serif']],
        'assets' => ['logo_light' => '', 'logo_dark' => '', 'mark' => ''],
        'default_layout' => 'brief',
        'header_html' => '<div id="'.$headerMarker.'">Header copy</div>',
        'custom_css' => '.'.$cssMarker.'{color:var(--capstan-color-primary)}',
        'tone_notes' => 'Concise and factual '.Str::random(8),
    ])->assertRedirect(route('branding.show'));

    $draft = resolve(BrandKitService::class)->read()['draft'];
    expect($draft['display_name'])->toBe($name)
        ->and($draft['colours']['light']['primary'])->toBe('#123abc')
        ->and($draft['fonts']['heading']['value'])->toBe('system-serif')
        ->and($draft['default_layout'])->toBe('brief')
        ->and($draft['assets'])->toBe(['logo_light' => null, 'logo_dark' => null, 'mark' => null]);

    $app = $this->actingAsVersioned($owner)->get(route('branding.show'))->assertOk()->assertSee($name);
    expect($app->content())->not->toContain('<div id="'.$headerMarker.'">', '<style>'.$cssMarker, 'data-capstan-brand="draft"');

    $invalid = 'Invalid retained '.Str::random(8);
    $this->from(route('branding.show'))->actingAsVersioned($owner)->patch(route('branding.draft.update'), [
        'display_name' => $invalid,
        'colours' => ['light' => ['primary' => 'red;}</style>']],
    ])->assertRedirect(route('branding.show'))->assertSessionHasErrors('colours.light.primary')->assertSessionHasInput('display_name', $invalid);
    expect(resolve(BrandKitService::class)->read()['draft']['display_name'])->toBe($name);
});

test('settings upload select remove preview apply and revert append immutable versions', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $firstName = 'First brand '.Str::random(8);
    $secondName = 'Second brand '.Str::random(8);

    $uploadResponse = $this->actingAsVersioned($owner)->post(route('branding.assets.store'), [
        'slot' => 'logo_light',
        'asset' => UploadedFile::fake()->createWithContent('test-logo.png', brandingSettingsPng()),
    ]);
    expect($uploadResponse->getStatusCode())->toBe(302, 'asset upload status');
    $uploadResponse->assertRedirect(route('branding.show'));
    $asset = BrandAsset::query()->sole();
    expect(resolve(BrandKitService::class)->read()['draft']['assets']['logo_light'])->toBe($asset->id)
        ->and(Storage::disk()->get($asset->storage_key))->toBe(brandingSettingsPng());

    $previewPage = $this->actingAsVersioned($owner)->get(route('branding.show'))->assertOk();
    preg_match('/src="([^"]*brand-previews[^"]*)"/', $previewPage->content(), $match);
    expect($match)->toHaveCount(2);
    $previewUrl = html_entity_decode($match[1]);
    $preview = $this->get($previewUrl)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    expect($preview->content())->toContain('data-testid="brand-preview-layout-dashboard"', $asset->id)
        ->and($preview->headers->get('Content-Security-Policy'))->toContain("img-src 'self' data:", "font-src 'none'");

    resolve(BrandKitService::class)->updateDraft($owner, [
        'display_name' => $firstName,
        'colours' => ['light' => ['primary' => '#112233']],
    ]);
    $this->actingAsVersioned($owner)
        ->post('https://app.brand-ui.test'.route('branding.apply', [], false), [
            'draft_fingerprint' => resolve(BrandKitService::class)->draftFingerprint(),
        ])
        ->assertRedirect('https://app.brand-ui.test'.route('branding.show', [], false));
    $first = BrandVersion::query()->sole();
    expect($first->configuration['display_name'])->toBe($firstName)
        ->and($this->get($previewUrl)->getStatusCode())->toBe(404);

    resolve(BrandKitService::class)->updateDraft($owner, [
        'display_name' => $secondName,
        'colours' => ['light' => ['primary' => '#445566']],
        'assets' => ['logo_light' => null],
    ]);
    $this->actingAsVersioned($owner)->post('https://app.brand-ui.test'.route('branding.apply', [], false), [
        'draft_fingerprint' => resolve(BrandKitService::class)->draftFingerprint(),
    ]);
    expect(resolve(BrandPageRenderer::class)->render('<html><head></head><body>existing</body></html>', 'text/html'))
        ->toContain('--capstan-color-primary:#445566')
        ->not->toContain('--capstan-color-primary:#112233');
    $firstSnapshot = $first->configuration;
    $failRevertPointer = true;
    BrandKit::updating(function (BrandKit $kit) use (&$failRevertPointer): void {
        if ($failRevertPointer && $kit->current_version_number === 3) {
            throw new RuntimeException('Test-created revert pointer failure');
        }
    });
    expect(fn () => resolve(BrandKitService::class)->revertToVersion($owner, 1))->toThrow(RuntimeException::class)
        ->and(BrandVersion::query()->count())->toBe(2)
        ->and(resolve(BrandKitService::class)->read()['current_version'])->toBe(2);
    $failRevertPointer = false;
    $this->actingAsVersioned($owner)
        ->post('https://app.brand-ui.test'.route('branding.versions.revert', ['version' => 1], false))
        ->assertRedirect('https://app.brand-ui.test'.route('branding.show', [], false));

    $state = resolve(BrandKitService::class)->read();
    expect(BrandVersion::query()->count())->toBe(3)
        ->and($state['current_version'])->toBe(3)
        ->and($state['current'])->toBe($firstSnapshot)
        ->and($state['draft'])->toBeNull()
        ->and($first->fresh()?->configuration)->toBe($firstSnapshot);
    expect(resolve(BrandPageRenderer::class)->render('<html><head></head><body>existing</body></html>', 'text/html'))
        ->toContain('--capstan-color-primary:#112233')
        ->not->toContain('--capstan-color-primary:#445566');
    $history = $this->actingAsVersioned($owner)->get('https://app.brand-ui.test'.route('branding.show', [], false))
        ->assertOk()
        ->assertSee('data-testid="branding-history-row"', false)
        ->assertSee($firstName)
        ->assertDontSee($secondName);
    expect(substr_count($history->content(), 'data-testid="branding-history-row"'))->toBe(3);
});

test('settings apply refuses a stale rendered draft fingerprint without changing brand state', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $service = resolve(BrandKitService::class);
    $service->updateDraft($owner, ['display_name' => 'Rendered draft '.Str::random(8)]);
    $page = $this->actingAsVersioned($owner)->get(route('branding.show'))->assertOk();
    preg_match('/name="draft_fingerprint" value="([0-9a-f]{64})"/', $page->content(), $matches);
    expect($matches)->toHaveCount(2);
    $renderedFingerprint = $matches[1];

    $service->updateDraft($owner, ['display_name' => 'Changed in another tab '.Str::random(8)]);
    $before = BrandKit::query()->sole()->only(['current_version_id', 'current_version_number', 'draft']);

    $this->from(route('branding.show'))->actingAsVersioned($owner)->post(route('branding.apply'), [
        'draft_fingerprint' => $renderedFingerprint,
    ])->assertRedirect(route('branding.show'))->assertSessionHasErrors('draft_fingerprint');

    expect(BrandVersion::query()->count())->toBe(0)
        ->and(BrandKit::query()->sole()->only(['current_version_id', 'current_version_number', 'draft']))->toBe($before);
});
