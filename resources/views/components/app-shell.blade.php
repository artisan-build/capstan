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
    $shellStylesheetUrl = $stylesheetUrl;
    $shellWordmarkUrl = $wordmarkUrl;

    if (\Illuminate\Support\Facades\Route::has('bfc.assets')) {
        $shellStylesheetUrl ??= route('bfc.assets', 'bfc.css').'?v='.substr((string) md5_file(base_path('vendor/artisan-build/built-for-cloud/resources/dist/bfc.css')), 0, 12);
        $shellWordmarkUrl ??= route('bfc.assets', 'img/scalpels-wordmark.webp');
    }

@endphp

@if ($shellUser)
    <x-sidebar-shell
        :brand="$brand"
        :title="$title"
        :user="$shellUser"
        :stylesheet-url="$stylesheetUrl"
        :wordmark-url="$wordmarkUrl"
        :logout-url="$logoutUrl"
    >
        <x-slot:sidebar>
            @include('partials.app-navigation')
        </x-slot:sidebar>
        <div data-testid="app-shell-content">
            {{ $slot }}
        </div>
    </x-sidebar-shell>
@else
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
            .app-shell-wordmark { display: inline-flex; align-items: center; min-height: 2.75rem; width: 8rem; }
            .app-shell-wordmark img { display: block; height: auto; max-width: 100%; }
            .app-shell-product { display: flex; align-items: center; gap: .75rem; min-width: 0; color: var(--color-clay-ink, #29231d); font-weight: 650; text-decoration: none; }
            .app-shell-product-mark { display: grid; flex: 0 0 auto; height: 2rem; place-items: center; width: 2rem; }
            .app-shell-product-mark img, .app-shell-product-mark svg { max-height: 100%; max-width: 100%; }
            .app-shell-product-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .app-shell-guest-header { align-items: center; background: var(--color-clay-paper, #faf3e8); border-bottom: 1px solid var(--color-clay-sand, #e7dccb); display: flex; min-height: 4.5rem; padding: .75rem 1.25rem; }
            .app-shell-guest-content { margin: 0 auto; max-width: 72rem; min-width: 0; padding: 1.5rem; }
            .app-shell-error { margin: 0 auto; max-width: 36rem; padding: 4rem 0; text-align: center; }
            .app-shell-error-code { font-size: .875rem; font-weight: 650; }
            .app-shell-error-title { color: var(--color-clay-ink, #29231d); font-size: 1.875rem; font-weight: 650; margin-top: .5rem; }
            @media (max-width: 1023px) {
                .app-shell-guest-content { padding: 1rem; }
            }
        </style>
        @include('partials.app-shell-accent')
        @stack('head')
    </head>
    <body
        data-testid="app-shell"
        class="app-shell-root bfc-body bg-clay-paper font-sans text-clay-soft antialiased [--color-white:var(--color-clay-surface)] [--color-zinc-50:var(--color-clay-surface)] [--color-zinc-100:var(--color-clay-slip)] [--color-zinc-200:var(--color-clay-sand)] [--color-zinc-300:var(--color-clay-edge)] [--color-zinc-400:var(--color-clay-dot)] [--color-zinc-500:var(--color-clay-brown)] [--color-zinc-600:var(--color-clay-soft)] [--color-zinc-700:var(--color-clay-soft)] [--color-zinc-800:var(--color-clay-ink)] [--color-zinc-900:var(--color-clay-ink)] [--color-zinc-950:var(--color-clay-ink)] [--color-accent:var(--color-clay-brown)] [--color-accent-content:var(--color-clay-iron)] [--color-accent-foreground:var(--color-clay-surface)]"
    >
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

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @stack('scripts')
        @fluxScripts
    </body>
</html>
@endif
