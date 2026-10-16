<?php

use App\Models\BrandAsset;
use App\Models\BrandVersion;
use App\Notifications\ExternalPageShareNotification;
use App\Support\BrandKitSchema;
use App\Support\BrandKitService;
use ArtisanBuild\BuiltForCloud\Actions\IssueHumanInvitation;
use ArtisanBuild\BuiltForCloud\Notifications\HumanInvitationNotification;
use ArtisanBuild\BuiltForCloud\StandaloneHandoff;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Notification as Notifications;
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

/**
 * The real Built for Cloud sign-in, password-request and invitation-accept
 * pages, each rendered by the package controller through <x-bfc-layout>.
 *
 * @return list<string>
 */
function appBrandBuiltForCloudShell(object $test, User $owner): array
{
    Notifications::fake();
    $invitation = resolve(IssueHumanInvitation::class)($owner, 'invitee-'.Str::lower(Str::random(8)).'@example.test', UserRole::Member);
    resolve('auth')->forgetGuards();

    $pages = [];
    foreach ([route('bfc.login'), route('bfc.password.request')] as $url) {
        $pages[] = (string) $test->get($url)->assertOk()->getContent();
    }
    // Follow the link the invitee's mail carries; the action reveals the token only there.
    $mail = Notifications::sent(new AnonymousNotifiable, HumanInvitationNotification::class)->sole();
    $handoff = $test->get((string) $mail->toMail(new AnonymousNotifiable)->actionUrl)->assertRedirect();
    $test->withCookie(StandaloneHandoff::COOKIE, (string) $handoff->getCookie(StandaloneHandoff::COOKIE)?->getValue());
    $pages[] = (string) $test->get(route('bfc.invitations.accept.form'))->assertOk()
        ->assertSee($invitation->invitation->email)->getContent();

    foreach ($pages as $page) {
        expect($page)->toContain('data-testid="bfc-layout"', 'data-testid="bfc-header-scalpels"', 'img/scalpels-wordmark.webp', 'bfc.css?v=');
    }

    return $pages;
}

/** @return array{dashboard: string, header: string, bfc: list<string>} */
function appBrandChrome(object $test, User $user): array
{
    $bfc = appBrandBuiltForCloudShell($test, $user);
    $dashboard = (string) $test->actingAsVersioned($user)->get(route('bfc.dashboard'))->assertOk()->getContent();
    $header = (string) Blade::render('<x-layouts::app.header>header-slot</x-layouts::app.header>');

    return ['dashboard' => $dashboard, 'header' => $header, 'bfc' => $bfc];
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

    foreach ([$chrome['dashboard'], $chrome['header'], ...$chrome['bfc']] as $html) {
        expect($html)->toContain('viewBox="0 0 40 42"', e($name));
        expectAbsent($html, 'data-testid="app-brand-icon"', 'data-testid="app-brand-accent"', 'data-testid="bfc-brand-accent"', '/brand-assets/', 'scalpels.app/img/products', $defaultAccent);
    }
    expect($chrome['dashboard'])->toContain('<title>'."\n    Dashboard - ".e($name));
    foreach ($chrome['bfc'] as $html) {
        expectAbsent($html, 'background: var(--color-accent)', 'border-color: var(--color-accent)', 'color: var(--color-accent-foreground)', 'color: var(--color-accent-content)');
        expect($html)->toContain('[--color-accent:var(--color-clay-brown)]', '[--color-accent-content:var(--color-clay-iron)]', '[--color-accent-foreground:var(--color-clay-surface)]');
    }

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
    foreach ([$chrome['dashboard'], $chrome['header'], ...$chrome['bfc'], $mails['share_html'], $mails['share_text'], $mails['invite_html'], $mails['invite_text']] as $html) {
        expect($html)->toContain(e((string) config('app.name')));
        expectAbsent($html, $markers['name'], $markers['light'], $markers['dark'], ...array_values($assets));
        expectNoBrandLeak($html, $markers);
    }
});

test('after apply the app chrome and Built for Cloud auth shell carry the applied name, isolated-origin icon and accent tokens only', function (): void {
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
    foreach ([$chrome['dashboard'], $chrome['header'], ...$chrome['bfc']] as $html) {
        expect($html)->toContain(e($markers['name']), 'data-testid="app-brand-icon" src="'.$markUrl.'"');
        expectAbsent($html, 'viewBox="0 0 40 42"', e((string) config('app.name')), $assets['logo_light'], $assets['logo_dark'], $draftMarkers['name'], 'https://app.chrome-brand.test/brand-assets/');
        expectNoBrandLeak($html, $markers);
        expectNoBrandLeak($html, $draftMarkers);
        $shell = str_contains($html, 'data-testid="bfc-layout"');
        $accent = Str::between($html, $shell ? '<style data-testid="bfc-brand-accent">' : '<style data-testid="app-brand-accent">', '</style>');
        expect($accent)->toContain(
            ($shell ? '.bfc-body' : ':root').' { --color-accent: '.$markers['light'].'; --color-accent-content: '.$markers['light'].';',
            ($shell ? '.dark .bfc-body' : '.dark').' { --color-accent: '.$markers['dark'].'; --color-accent-content: '.$markers['dark'].';',
        );
        expectAbsent($accent, $draftMarkers['light'], $draftMarkers['dark']);
        if ($shell) {
            expect($accent)
                ->toMatch('/\.bfc-body\s+\.bfc-auth\s+button\[type="submit"\]\s*\{\s*background:\s*var\(--color-accent\);\s*border-color:\s*var\(--color-accent\);\s*color:\s*var\(--color-accent-foreground\);\s*\}/')
                ->toMatch('/\.bfc-body\s+\.bfc-link\s*\{\s*color:\s*var\(--color-accent-content\);\s*\}/');
        }
    }
    expect($chrome['dashboard'])->toContain('Dashboard - '.e($markers['name']));
    foreach ($chrome['bfc'] as $html) {
        expect($html)->toContain('<link rel="icon" href="'.$markUrl.'">');
        expectAbsent($html, 'scalpels.app/img/products');
    }

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

    $chrome = appBrandChrome($this, $owner);
    $mail = appBrandMails($owner)['invite_html'];
    foreach ([$chrome['dashboard'], ...$chrome['bfc']] as $html) {
        expect($html)->toContain(e($two['name']), $two['light'], $two['dark'],
            'src="https://render.chrome-brand.test/brand-assets/'.$assets['logo_light'].'"',
            'src="https://render.chrome-brand.test/brand-assets/'.$assets['logo_dark'].'"',
        );
        expectAbsent($html, e($one['name']), $one['light'], $one['dark'], $assets['mark']);
    }
    expect($mail)->toContain(e($two['name']), $two['light'], $assets['logo_light']);
    expectAbsent($mail, e($one['name']), $one['light'], $assets['mark']);

    $service->revertToVersion($owner, $v1->version_number);
    $chrome = appBrandChrome($this, $owner);
    $mail = appBrandMails($owner)['invite_html'];
    foreach ([$chrome['dashboard'], ...$chrome['bfc']] as $html) {
        expect($html)->toContain(e($one['name']), $one['light'], $one['dark'], 'src="https://render.chrome-brand.test/brand-assets/'.$assets['mark'].'"');
        expectAbsent($html, e($two['name']), $two['light'], $two['dark'], $assets['logo_light'], $assets['logo_dark']);
    }
    expect($mail)->toContain(e($one['name']), $one['light'], 'src="https://render.chrome-brand.test/brand-assets/'.$assets['mark'].'"');
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
    foreach ([$chrome['dashboard'], $chrome['header'], ...$chrome['bfc'], $mails['share_html'], $mails['invite_html'], $mails['share_text'], $mails['invite_text']] as $html) {
        expect($html)->toContain(e((string) config('app.name')));
        expectAbsent($html, $markers['name'], $markers['light'], '/brand-assets/', 'data-testid="app-brand-icon"', 'data-testid="app-brand-accent"', 'data-testid="bfc-brand-accent"', 'scalpels.app/img/products', ...array_values($assets));
    }
    foreach ([$chrome['dashboard'], $chrome['header'], ...$chrome['bfc']] as $html) {
        expect($html)->toContain('viewBox="0 0 40 42"');
    }
})->with([
    'feature off' => [['capstan.features.artifacts' => false]],
    'render origin unset' => [['capstan.artifacts.render_origin' => null]],
    'render origin on the app host' => [['capstan.artifacts.render_origin' => 'https://app.chrome-brand.test']],
]);

/** @return array<string, int> */
function appBrandMailTags(string $html): array
{
    preg_match_all('/<([a-z][a-z0-9]*)\b/i', $html, $matches);

    return array_count_values(array_map(strtolower(...), $matches[1]));
}

test('an applied display name stays literal text in every Markdown-parsed mail slot', function (string $syntax): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $service = resolve(BrandKitService::class);
    $service->updateDraft($owner, ['display_name' => 'Plain '.Str::random(8)]);
    $service->applyDraft($owner);
    $plain = appBrandMails($owner);
    $name = 'Hostile '.Str::random(6).' '.$syntax;
    $service->updateDraft($owner, ['display_name' => $name]);
    $service->applyDraft($owner);

    $mails = appBrandMails($owner);
    $year = date('Y');
    foreach (['share', 'invite'] as $kind) {
        $html = $mails[$kind.'_html'];
        expect(appBrandMailTags($html))->toBe(appBrandMailTags($plain[$kind.'_html']));
        preg_match_all('/<[^>]+>/', $html, $tags);
        preg_match_all('/\b(href|src)="([^"]*)"/', implode('', $tags[0]), $links, PREG_SET_ORDER);
        foreach ($links as [, $attribute, $value]) {
            expect($attribute)->toBe('href')
                ->and($value)->toBeIn([e((string) config('app.url')), e($mails[$kind.'_url'])]);
        }
        $visible = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        expect($visible)->toContain("Regards,\n".$name."\n", '© '.$year.' '.$name.'.');

        $text = $mails[$kind.'_text'];
        expect($text)->toContain("Regards,\n".$name."\n", '© '.$year.' '.$name.'. ', $mails[$kind.'_url'])
            ->and(substr_count($text, $name))->toBe(3);
        expectAbsent(str_replace($name, '', $text), '&#', '<!--', '\\');
    }
})->with([
    'image' => ['![px](https://tracker.example/p.png)'],
    'link' => ["[Pay](https://evil.example/pay) [ref][r]\n[r]: https://evil.example/ref"],
    'emphasis' => ['*em* **strong** _u_ __uu__ ~~s~~'],
    'code' => ["`code` ``more`` and\n```\nfenced"],
    'autolink and URL' => ['<https://evil.example/auto> <a@evil.example> https://evil.example/bare www.evil.example a@evil.example'],
    'backslash' => ['\*x* \\[x](https://evil.example/b) \[y] end\\'],
    'raw HTML' => ['<img src="https://tracker.example/x.png"> <b>bold</b> <!-- c --> <a href="https://evil.example/r">r</a>'],
    'entities and blocks' => ["&amp; &#91;x&#93; &lt;b&gt;\n# H\n- item\n> q"],
]);
