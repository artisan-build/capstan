<?php

use App\Models\BrandAsset;
use App\Models\BrandVersion;
use App\Notifications\ExternalPageShareNotification;
use App\Support\BrandKitSchema;
use App\Support\BrandKitService;
use ArtisanBuild\BuiltForCloud\Notifications\HumanInvitationNotification;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('c', 32)),
        'app.name' => 'Configured '.Str::random(8),
        'app.url' => 'https://app.chrome-brand.test',
        'capstan.features.artifacts' => true,
        'capstan.artifacts.render_origin' => 'https://render.chrome-brand.test',
    ]);
    Feature::flushCache();
    Storage::fake();
    // The starter-kit auth layouts link to a `home` route Capstan never
    // registers (its sign-in pages use Built for Cloud's layout), so name one
    // here to render those layouts directly.
    Route::get('/auth-layout-home-fixture', fn (): string => '')->name('home');
    Route::getRoutes()->refreshNameLookups();
});

function appBrandPng(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
}

/**
 * Distinctive values for every brand field, including the ones that must never
 * reach the app origin or mail.
 *
 * @return array{changes: array<string, mixed>, markers: array{name: string, light: string, dark: string, header: string, footer: string, css: string, tone: string}}
 */
function appBrandChanges(): array
{
    $proof = Str::lower(Str::random(10));
    $light = '#'.substr(hash('sha256', 'light'.$proof), 0, 6);
    $dark = '#'.substr(hash('sha256', 'dark'.$proof), 0, 6);
    $markers = [
        'name' => 'Brand '.$proof,
        'light' => $light,
        'dark' => $dark,
        'header' => 'header-'.$proof,
        'footer' => 'footer-'.$proof,
        'css' => '.css-'.$proof,
        'tone' => 'tone-'.$proof,
    ];

    return [
        'changes' => [
            'display_name' => $markers['name'],
            'colours' => ['light' => ['accent' => $light], 'dark' => ['accent' => $dark]],
            'header_html' => '<b>'.$markers['header'].'</b>',
            'footer_html' => '<i>'.$markers['footer'].'</i>',
            'custom_css' => $markers['css'].'{color:#abc}',
            'tone_notes' => $markers['tone'],
        ],
        'markers' => $markers,
    ];
}

/** @return array{logo_light: string, logo_dark: string, mark: string} */
function appBrandAssets(User $owner): array
{
    $service = resolve(BrandKitService::class);

    return [
        'logo_light' => $service->uploadAsset($owner, 'logo', appBrandPng())->id,
        'logo_dark' => $service->uploadAsset($owner, 'logo', appBrandPng())->id,
        'mark' => $service->uploadAsset($owner, 'mark', appBrandPng())->id,
    ];
}

/** @return array{share_html: string, share_text: string, invite_html: string, invite_text: string, share_url: string, invite_url: string} */
function appBrandMails(User $notifiable): array
{
    $shareUrl = 'https://app.chrome-brand.test/page-shares/'.Str::uuid();
    $share = new ExternalPageShareNotification((string) Str::uuid(), 'Shared '.Str::random(8), $shareUrl);
    $invite = new HumanInvitationNotification((string) Str::uuid(), 'token-'.Str::random(16), 'invitee@example.test');
    $render = function (Notification $notification) use ($notifiable): array {
        $message = $notification->toMail($notifiable);

        return [
            (string) $message->render(),
            (string) resolve(Markdown::class)->renderText($message->markdown, $message->data()),
            (string) $message->actionUrl,
        ];
    };
    [$shareHtml, $shareText, $shareAction] = $render($share);
    [$inviteHtml, $inviteText, $inviteAction] = $render($invite);

    return [
        'share_html' => $shareHtml,
        'share_text' => $shareText,
        'invite_html' => $inviteHtml,
        'invite_text' => $inviteText,
        'share_url' => $shareAction,
        'invite_url' => $inviteAction,
    ];
}

/** @return array{dashboard: string, header: string, auth: list<string>} */
function appBrandChrome(object $test, User $user): array
{
    $dashboard = (string) $test->actingAsVersioned($user)->get(route('bfc.dashboard'))->assertOk()->getContent();
    $header = (string) Blade::render('<x-layouts::app.header>header-slot</x-layouts::app.header>');
    $auth = [];
    foreach (['simple', 'card', 'split'] as $layout) {
        $auth[] = (string) Blade::render("<x-layouts::auth.{$layout}>auth-slot</x-layouts::auth.{$layout}>");
    }

    return ['dashboard' => $dashboard, 'header' => $header, 'auth' => $auth];
}

/**
 * Pest's negated toContain with several needles passes when ANY one is
 * missing, so every forbidden value is checked on its own.
 */
function expectAbsent(string $haystack, string ...$needles): void
{
    foreach ($needles as $needle) {
        expect($haystack)->not->toContain($needle);
    }
}

/** @param array<string, string> $markers */
function expectNoBrandLeak(string $html, array $markers): void
{
    expectAbsent($html, $markers['header'], $markers['footer'], $markers['css'], $markers['tone']);
}

test('without an applied kit app chrome and mail fall back to the configured name, built-in icon and neutral accent', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $name = (string) config('app.name');
    $defaultAccent = resolve(BrandKitSchema::class)->defaults()['colours']['light']['accent'];
    $chrome = appBrandChrome($this, $owner);

    foreach ([$chrome['dashboard'], $chrome['header'], ...$chrome['auth']] as $html) {
        expect($html)->toContain('viewBox="0 0 40 42"', e($name));
        expectAbsent($html, 'data-testid="app-brand-icon"', 'data-testid="app-brand-accent"', '/brand-assets/', $defaultAccent);
    }
    expect($chrome['dashboard'])->toContain('<title>'."\n    Dashboard - ".e($name));

    $mails = appBrandMails($owner);
    foreach (['share_html', 'invite_html'] as $key) {
        expect($mails[$key])->toContain('data-testid="mail-brand-header"', e($name), $mails[$key === 'share_html' ? 'share_url' : 'invite_url']);
        expectAbsent($mails[$key], '<img', '/brand-assets/', 'laravel.com', $defaultAccent);
        expect(Str::between($mails[$key], 'data-testid="mail-brand-header"', '</td>'))->toContain(e($name));
    }
    foreach (['share_text', 'invite_text'] as $key) {
        expect($mails[$key])->toContain($name, $mails[$key === 'share_text' ? 'share_url' : 'invite_url']);
        expectAbsent($mails[$key], '<a ', '<img');
    }
});

test('a draft changes neither app chrome nor notification mail until it is applied', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $service = resolve(BrandKitService::class);
    ['changes' => $changes, 'markers' => $markers] = appBrandChanges();
    $assets = appBrandAssets($owner);
    $service->updateDraft($owner, [...$changes, 'assets' => $assets]);

    $chrome = appBrandChrome($this, $owner);
    $mails = appBrandMails($owner);
    foreach ([$chrome['dashboard'], $chrome['header'], ...$chrome['auth'], $mails['share_html'], $mails['share_text'], $mails['invite_html'], $mails['invite_text']] as $html) {
        expect($html)->toContain(e((string) config('app.name')));
        expectAbsent($html, $markers['name'], $markers['light'], $markers['dark'], ...array_values($assets));
        expectNoBrandLeak($html, $markers);
    }
});

test('after apply the app chrome and auth layouts carry the applied name, isolated-origin icon and accent tokens only', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $service = resolve(BrandKitService::class);
    ['changes' => $changes, 'markers' => $markers] = appBrandChanges();
    $assets = appBrandAssets($owner);
    $service->updateDraft($owner, [...$changes, 'assets' => $assets]);
    $service->applyDraft($owner);
    // A newer draft must not leak either.
    ['changes' => $draft, 'markers' => $draftMarkers] = appBrandChanges();
    $service->updateDraft($owner, $draft);

    $markUrl = 'https://render.chrome-brand.test/brand-assets/'.$assets['mark'];
    $chrome = appBrandChrome($this, $owner);
    foreach ([$chrome['dashboard'], $chrome['header'], ...$chrome['auth']] as $html) {
        expect($html)->toContain(e($markers['name']), 'data-testid="app-brand-icon" src="'.$markUrl.'"');
        expectAbsent($html, 'viewBox="0 0 40 42"', e((string) config('app.name')), $assets['logo_light'], $assets['logo_dark'], $draftMarkers['name'], 'https://app.chrome-brand.test/brand-assets/');
        expectNoBrandLeak($html, $markers);
        expectNoBrandLeak($html, $draftMarkers);
        $accent = Str::between($html, '<style data-testid="app-brand-accent">', '</style>');
        expect($accent)->toContain(
            ':root { --color-accent: '.$markers['light'].'; --color-accent-content: '.$markers['light'].';',
            '.dark { --color-accent: '.$markers['dark'].'; --color-accent-content: '.$markers['dark'].';',
        );
        expectAbsent($accent, $draftMarkers['light'], $draftMarkers['dark']);
    }
    expect($chrome['dashboard'])->toContain('Dashboard - '.e($markers['name']));

    $served = $this->get($markUrl)->assertOk();
    expect($served->headers->get('Content-Type'))->toBe('image/png')
        ->and($this->get('https://render.chrome-brand.test/brand-assets/'.Str::uuid())->getStatusCode())->toBe(404);
});

test('after apply both notification mails carry the applied name, logo and accent without chrome, CSS or draft values', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $service = resolve(BrandKitService::class);
    ['changes' => $changes, 'markers' => $markers] = appBrandChanges();
    $assets = appBrandAssets($owner);
    $service->updateDraft($owner, [...$changes, 'assets' => $assets]);
    $service->applyDraft($owner);
    ['changes' => $draft, 'markers' => $draftMarkers] = appBrandChanges();
    $service->updateDraft($owner, $draft);

    $mails = appBrandMails($owner);
    $logoUrl = 'https://render.chrome-brand.test/brand-assets/'.$assets['logo_light'];
    foreach (['share' => 'View page', 'invite' => 'Accept invitation'] as $kind => $action) {
        $html = $mails[$kind.'_html'];
        $header = Str::between($html, 'data-testid="mail-brand-header"', '</td>');
        expect($header)->toContain('<img src="'.$logoUrl.'"', 'alt="'.e($markers['name']).'"')
            ->and($html)->toContain('<title>'.e($markers['name']).'</title>', 'href="'.e($mails[$kind.'_url']).'"')
            ->and($html)->toMatch('/<a href="'.preg_quote(e($mails[$kind.'_url']), '/').'" class="button button-primary"[^>]*style="[^"]*background-color: '.preg_quote($markers['light'], '/').'/');
        expectAbsent($html, 'laravel.com', $assets['mark'], $assets['logo_dark'], $draftMarkers['name'], $draftMarkers['light'], e((string) config('app.name')));
        expectNoBrandLeak($html, $markers);
        expectNoBrandLeak($html, $draftMarkers);

        $text = $mails[$kind.'_text'];
        expect($text)->toContain($markers['name'], $mails[$kind.'_url'], $action);
        expectAbsent($text, '<img', '/brand-assets/', $draftMarkers['name'], (string) config('app.name'));
        expectNoBrandLeak($text, $markers);
    }
    expect($mails['share_url'])->toStartWith('https://app.chrome-brand.test/page-shares/')
        ->and($mails['invite_url'])->toContain('token-');
});

test('switching or reverting the applied version switches app and mail branding without rewriting history', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $service = resolve(BrandKitService::class);
    ['changes' => $first, 'markers' => $one] = appBrandChanges();
    $assets = appBrandAssets($owner);
    $service->updateDraft($owner, [...$first, 'assets' => ['mark' => $assets['mark']]]);
    $v1 = $service->applyDraft($owner);
    ['changes' => $second, 'markers' => $two] = appBrandChanges();
    $service->updateDraft($owner, [...$second, 'assets' => ['mark' => null, 'logo_light' => $assets['logo_light'], 'logo_dark' => $assets['logo_dark']]]);
    $v2 = $service->applyDraft($owner);
    $snapshots = BrandVersion::query()->orderBy('version_number')->pluck('configuration', 'id')->all();

    $chrome = appBrandChrome($this, $owner)['dashboard'];
    $mail = appBrandMails($owner)['invite_html'];
    expect($chrome)->toContain(e($two['name']), $two['light'], $two['dark'],
        'src="https://render.chrome-brand.test/brand-assets/'.$assets['logo_light'].'"',
        'src="https://render.chrome-brand.test/brand-assets/'.$assets['logo_dark'].'"',
    );
    expect($mail)->toContain(e($two['name']), $two['light'], $assets['logo_light']);
    expectAbsent($chrome, e($one['name']), $one['light'], $assets['mark']);
    expectAbsent($mail, e($one['name']), $one['light'], $assets['mark']);

    $service->revertToVersion($owner, $v1->version_number);
    $chrome = appBrandChrome($this, $owner)['dashboard'];
    $mail = appBrandMails($owner)['invite_html'];
    expect($chrome)->toContain(e($one['name']), $one['light'], $one['dark'], 'src="https://render.chrome-brand.test/brand-assets/'.$assets['mark'].'"')
        ->and($mail)->toContain(e($one['name']), $one['light'], 'src="https://render.chrome-brand.test/brand-assets/'.$assets['mark'].'"');
    expectAbsent($chrome, e($two['name']), $two['light'], $assets['logo_light'], $assets['logo_dark']);
    expectAbsent($mail, e($two['name']), $two['light'], $assets['logo_light']);
    expect(BrandVersion::query()->count())->toBe(3)
        ->and(BrandVersion::query()->whereKey(array_keys($snapshots))->orderBy('version_number')->pluck('configuration', 'id')->all())->toBe($snapshots)
        ->and([$v1->fresh()->version_number, $v2->fresh()->version_number])->toBe([1, 2]);
});

test('feature-off or an unconfigured render origin falls back without exposing any applied logo', function (array $config): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $service = resolve(BrandKitService::class);
    ['changes' => $changes, 'markers' => $markers] = appBrandChanges();
    $assets = appBrandAssets($owner);
    $service->updateDraft($owner, [...$changes, 'assets' => $assets]);
    $service->applyDraft($owner);
    expect(BrandAsset::query()->count())->toBe(3);

    config($config);
    Feature::flushCache();
    $chrome = appBrandChrome($this, $owner);
    $mails = appBrandMails($owner);
    foreach ([$chrome['dashboard'], $chrome['header'], ...$chrome['auth'], $mails['share_html'], $mails['invite_html'], $mails['share_text'], $mails['invite_text']] as $html) {
        expect($html)->toContain(e((string) config('app.name')));
        expectAbsent($html, $markers['name'], $markers['light'], '/brand-assets/', 'data-testid="app-brand-icon"', 'data-testid="app-brand-accent"', ...array_values($assets));
    }
    foreach ([$chrome['dashboard'], $chrome['header'], ...$chrome['auth']] as $html) {
        expect($html)->toContain('viewBox="0 0 40 42"');
    }
})->with([
    'feature off' => [['capstan.features.artifacts' => false]],
    'render origin unset' => [['capstan.artifacts.render_origin' => null]],
    'render origin on the app host' => [['capstan.artifacts.render_origin' => 'https://app.chrome-brand.test']],
]);
