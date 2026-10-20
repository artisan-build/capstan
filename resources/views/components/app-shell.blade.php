@props([
    'title' => null,
    'stylesheetUrl' => null,
    'wordmarkUrl' => null,
    'user' => null,
    'logoutUrl' => null,
])

@inject('appBrand', \App\Support\AppBrand::class)
@php
    $brand = rescue(
        fn () => $appBrand->current(),
        new \App\Support\AppBrandValues(
            displayName: (string) config('app.name', 'Capstan'),
            accentLight: null,
            accentDark: null,
            markUrl: null,
            logoLightUrl: null,
            logoDarkUrl: null,
        ),
        false,
    );
    $shellUser = $user ?? auth()->user();
    $shellLogoutUrl = $logoutUrl ?? (\Illuminate\Support\Facades\Route::has('bfc.ui.logout') ? route('bfc.ui.logout') : null);
    $shellStylesheetUrl = $stylesheetUrl;
    $shellWordmarkUrl = $wordmarkUrl;

    if (\Illuminate\Support\Facades\Route::has('bfc.assets')) {
        $shellStylesheetUrl ??= route('bfc.assets', 'bfc.css').'?v='.substr((string) md5_file(base_path('vendor/artisan-build/built-for-cloud/resources/dist/bfc.css')), 0, 12);
        $shellWordmarkUrl ??= route('bfc.assets', 'img/scalpels-wordmark.webp');
    }

    $accountIsCurrent = request()->routeIs(
        'bfc.ui.*',
        'bfc.members.*',
        'bfc.sessions.*',
        'bfc.transitions.*',
        'bfc.device.*',
        'bfc.loopback.*',
    ) || request()->is('settings', 'settings/*', 'bfc/device', 'bfc/members', 'bfc/me/*', 'bfc/transitions/*', 'bfc/loopback/*');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => $title])
        <meta name="theme-color" content="#faf3e8">
        @if ($shellStylesheetUrl)
            <link rel="stylesheet" href="{{ $shellStylesheetUrl }}">
        @endif
        <style>
            .app-shell-root { margin: 0; min-height: 100vh; overflow-x: hidden; }
            .app-shell-frame { display: grid; grid-template-columns: 17rem minmax(0, 1fr); min-height: 100vh; }
            .app-shell-navigation { min-width: 0; }
            .app-shell-navigation > summary { align-items: center; background: var(--color-clay-surface, #fffdf8); border-right: 1px solid var(--color-clay-sand, #e7dccb); box-sizing: border-box; display: flex; min-height: 4.75rem; padding: 1rem; width: 17rem; }
            .app-shell-navigation > summary::-webkit-details-marker { display: none; }
            .app-shell-sidebar { box-sizing: border-box; display: flex; flex-direction: column; gap: 1rem; min-height: calc(100vh - 4.75rem); padding: 0 1rem 1rem; width: 17rem; background: var(--color-clay-surface, #fffdf8); border-right: 1px solid var(--color-clay-sand, #e7dccb); }
            .app-shell-wordmark { display: inline-flex; align-items: center; min-height: 2.75rem; width: 8rem; }
            .app-shell-wordmark img { display: block; height: auto; max-width: 100%; }
            .app-shell-menu-affordance { display: none; }
            .app-shell-product { display: flex; align-items: center; gap: .75rem; min-width: 0; color: var(--color-clay-ink, #29231d); font-weight: 650; text-decoration: none; }
            .app-shell-product-mark { display: grid; flex: 0 0 auto; height: 2rem; place-items: center; width: 2rem; }
            .app-shell-product-mark img, .app-shell-product-mark svg { max-height: 100%; max-width: 100%; }
            .app-shell-product-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .app-shell-nav { display: grid; gap: .25rem; }
            .app-shell-nav-link { align-items: center; border-radius: .65rem; color: var(--color-clay-soft, #574d42); display: flex; min-height: 2.5rem; padding: .55rem .75rem; text-decoration: none; }
            .app-shell-nav-link:hover { background: var(--color-clay-slip, #f3eadf); color: var(--color-clay-ink, #29231d); }
            .app-shell-nav-link[aria-current="page"] { background: var(--color-accent, #6f5b46); color: var(--color-accent-foreground, #fffdf8); font-weight: 650; }
            .app-shell-account { border-top: 1px solid var(--color-clay-sand, #e7dccb); display: grid; gap: .5rem; margin-top: auto; padding-top: 1rem; }
            .app-shell-account-copy { min-width: 0; padding: 0 .75rem; }
            .app-shell-account-copy > * { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .app-shell-account-copy small { color: var(--color-clay-muted, #75695d); }
            .app-shell-logout { background: transparent; border: 0; cursor: pointer; font: inherit; text-align: left; width: 100%; }
            .app-shell-mobile-only { display: none; }
            .app-shell-content { box-sizing: border-box; min-width: 0; padding: 1.5rem; }
            .app-shell-guest-header { align-items: center; background: var(--color-clay-paper, #faf3e8); border-bottom: 1px solid var(--color-clay-sand, #e7dccb); display: flex; min-height: 4.5rem; padding: .75rem 1.25rem; }
            .app-shell-guest-content { margin: 0 auto; max-width: 72rem; min-width: 0; padding: 1.5rem; }
            .app-shell-error { margin: 0 auto; max-width: 36rem; padding: 4rem 0; text-align: center; }
            .app-shell-error-code { font-size: .875rem; font-weight: 650; }
            .app-shell-error-title { color: var(--color-clay-ink, #29231d); font-size: 1.875rem; font-weight: 650; margin-top: .5rem; }
            @media (max-width: 1023px) {
                .app-shell-frame { display: block; }
                .app-shell-navigation > summary { background: var(--color-clay-paper, #faf3e8); border-bottom: 1px solid var(--color-clay-sand, #e7dccb); border-right: 0; color: var(--color-clay-ink, #29231d); cursor: pointer; gap: .75rem; min-height: 4rem; padding: .65rem 1rem; width: 100%; }
                .app-shell-menu-affordance { align-items: center; display: flex; gap: .5rem; margin-left: auto; }
                .app-shell-menu-icon { height: 1.5rem; width: 1.5rem; }
                .app-shell-sidebar { min-height: auto; padding-top: 1rem; width: 100%; }
                .app-shell-mobile-only { display: block; }
                .app-shell-desktop-only { display: none; }
                .app-shell-content, .app-shell-guest-content { padding: 1rem; }
            }
        </style>
        @if ($brand->hasAccent())
            <style data-testid="app-shell-accent">
                .bfc-body { --color-accent: {{ $brand->accentLight }} !important; --color-accent-content: {{ $brand->accentLight }} !important; --color-accent-foreground: {{ \App\Support\AppBrandValues::foregroundFor($brand->accentLight) }} !important; }
                .dark .bfc-body { --color-accent: {{ $brand->accentDark }} !important; --color-accent-content: {{ $brand->accentDark }} !important; --color-accent-foreground: {{ \App\Support\AppBrandValues::foregroundFor($brand->accentDark) }} !important; }
                .bfc-body .bfc-auth button[type="submit"] { background: var(--color-accent); border-color: var(--color-accent); color: var(--color-accent-foreground); }
                .bfc-body .bfc-link { color: var(--color-accent-content); }
            </style>
        @endif
        @stack('head')
    </head>
    <body
        data-testid="app-shell"
        class="app-shell-root bfc-body bg-clay-paper font-sans text-clay-soft antialiased [--color-white:var(--color-clay-surface)] [--color-zinc-50:var(--color-clay-surface)] [--color-zinc-100:var(--color-clay-slip)] [--color-zinc-200:var(--color-clay-sand)] [--color-zinc-300:var(--color-clay-edge)] [--color-zinc-400:var(--color-clay-dot)] [--color-zinc-500:var(--color-clay-brown)] [--color-zinc-600:var(--color-clay-soft)] [--color-zinc-700:var(--color-clay-soft)] [--color-zinc-800:var(--color-clay-ink)] [--color-zinc-900:var(--color-clay-ink)] [--color-zinc-950:var(--color-clay-ink)] [--color-accent:var(--color-clay-brown)] [--color-accent-content:var(--color-clay-iron)] [--color-accent-foreground:var(--color-clay-surface)]"
    >
        @if ($shellUser)
            <div class="app-shell-frame">
                <details class="app-shell-navigation" data-app-shell-navigation open>
                    <summary data-testid="app-shell-mobile-trigger">
                        <span data-testid="app-shell-brand" class="app-shell-wordmark">
                            @if ($shellWordmarkUrl)
                                <img src="{{ $shellWordmarkUrl }}" alt="{{ __('Scalpels dashboard') }}" width="480" height="138">
                            @else
                                <span>{{ __('Scalpels') }}</span>
                            @endif
                        </span>
                        <span class="app-shell-menu-affordance">
                            <svg class="app-shell-menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                            <span>{{ __('Menu') }}</span>
                        </span>
                    </summary>

                    <aside data-testid="app-shell-sidebar" class="app-shell-sidebar">
                        <a class="app-shell-product" href="{{ route('bfc.dashboard') }}">
                            <span class="app-shell-product-mark"><x-app-logo-icon /></span>
                            <span class="app-shell-product-name">{{ $brand->displayName }}</span>
                        </a>

                        <nav class="app-shell-nav" aria-label="{{ __('Platform') }}">
                            <a class="app-shell-nav-link" href="{{ route('bfc.dashboard') }}" @if (request()->routeIs('bfc.dashboard')) aria-current="page" @endif>{{ __('Dashboard') }}</a>
                            <a class="app-shell-nav-link" href="{{ route('postmaster.map') }}" @if (request()->routeIs('postmaster.*')) aria-current="page" @endif>{{ __('Postmaster') }}</a>
                            <a data-testid="work-navigation" class="app-shell-nav-link" href="{{ route('work.index') }}" @if (request()->routeIs('work.*')) aria-current="page" @endif>{{ __('Work') }}</a>
                            @if (\Laravel\Pennant\Feature::active(\App\Features\Artifacts::class))
                                <a data-testid="pages-navigation" class="app-shell-nav-link" href="{{ route('pages.index') }}" @if (request()->routeIs('pages.*', 'artifacts.share')) aria-current="page" @endif>{{ __('Pages') }}</a>
                                @if (\ArtisanBuild\BuiltForCloud\RolePolicy::canManageMembers($shellUser->role))
                                    <a data-testid="branding-navigation" class="app-shell-nav-link" href="{{ route('branding.show') }}" @if (request()->routeIs('branding.*')) aria-current="page" @endif>{{ __('Branding') }}</a>
                                @endif
                            @endif
                            <a data-testid="account-navigation" class="app-shell-nav-link" href="{{ route('bfc.ui.home') }}" @if ($accountIsCurrent) aria-current="page" @endif>{{ __('Account') }}</a>
                        </nav>

                        <div class="app-shell-account">
                            <div class="app-shell-account-copy">
                                <strong>{{ $shellUser->name }}</strong>
                                <small>{{ $shellUser->email }}</small>
                            </div>
                            <a data-testid="desktop-settings-navigation" class="app-shell-nav-link app-shell-desktop-only" href="{{ route('bfc.ui.home') }}">{{ __('Settings') }}</a>
                            <a data-testid="mobile-settings-navigation" class="app-shell-nav-link app-shell-mobile-only" href="{{ route('bfc.ui.home') }}">{{ __('Settings') }}</a>
                            @if ($shellLogoutUrl)
                                <form data-testid="mobile-logout-form" method="POST" action="{{ $shellLogoutUrl }}">
                                    @csrf
                                    <button data-testid="account-logout" class="app-shell-nav-link app-shell-logout" type="submit">{{ __('Log out') }}</button>
                                </form>
                            @endif
                        </div>
                    </aside>
                </details>

                <main data-testid="app-shell-content" class="app-shell-content">
                    {{ $slot }}
                </main>
            </div>
        @else
            <header class="app-shell-guest-header">
                <a
                    data-testid="app-shell-brand"
                    class="app-shell-wordmark"
                    href="{{ \ArtisanBuild\BuiltForCloud\View\Layout::SCALPELS_URL }}"
                >
                    @if ($shellWordmarkUrl)
                        <img src="{{ $shellWordmarkUrl }}" alt="{{ __('Scalpels dashboard') }}" width="480" height="138">
                    @else
                        <span>{{ __('Scalpels') }}</span>
                    @endif
                </a>
                <span class="app-shell-product">
                    <span class="app-shell-product-mark"><x-app-logo-icon /></span>
                    <span class="app-shell-product-name">{{ $brand->displayName }}</span>
                </span>
            </header>
            <main data-testid="app-shell-content" class="app-shell-guest-content">
                {{ $slot }}
            </main>
        @endif

        <script>
            (() => {
                const mobile = window.matchMedia('(max-width: 1023px)');
                const syncNavigation = event => document.querySelectorAll('[data-app-shell-navigation]')
                    .forEach(navigation => navigation.open = ! event.matches);

                syncNavigation(mobile);
                mobile.addEventListener('change', syncNavigation);
            })();
        </script>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @stack('scripts')
        @fluxScripts
    </body>
</html>
