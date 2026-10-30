{{-- Consumer override of the built-for-cloud shell layout.
     Only Capstan's branded head/product rendering, structural hooks and Flux/toast
     hooks differ. Keep the native package shell structure in sync on upgrades. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-testid="app-shell">
<head>
    @include('partials.head', ['title' => $title])
    <meta name="theme-color" content="#faf3e8">
    @if ($stylesheetUrl)
        <link rel="stylesheet" href="{{ $stylesheetUrl }}">
    @endif
    @stack('head')
    @include('partials.app-shell-accent')
    <style>
        .bfc-sidebar-slot .bfc-shell-link[aria-current="page"] { background: var(--color-accent, #6f5b46); color: var(--color-accent-foreground, #fffdf8); font-weight: 650; }
        .app-shell-error { margin: 0 auto; max-width: 36rem; padding: 4rem 0; text-align: center; }
        .app-shell-error-code { font-size: .875rem; font-weight: 650; }
        .app-shell-error-title { color: var(--color-clay-ink, #29231d); font-size: 1.875rem; font-weight: 650; margin-top: .5rem; }
    </style>
</head>
<body data-testid="bfc-layout"
      class="bfc-body min-h-screen bg-clay-paper font-sans text-clay-soft antialiased
             [--color-white:var(--color-clay-surface)] [--color-zinc-50:var(--color-clay-surface)] [--color-zinc-100:var(--color-clay-slip)] [--color-zinc-200:var(--color-clay-sand)] [--color-zinc-300:var(--color-clay-edge)] [--color-zinc-400:var(--color-clay-dot)] [--color-zinc-500:var(--color-clay-brown)] [--color-zinc-600:var(--color-clay-soft)] [--color-zinc-700:var(--color-clay-soft)] [--color-zinc-800:var(--color-clay-ink)] [--color-zinc-900:var(--color-clay-ink)] [--color-zinc-950:var(--color-clay-ink)] [--color-accent:var(--color-clay-brown)] [--color-accent-content:var(--color-clay-iron)] [--color-accent-foreground:var(--color-clay-surface)]">
    <a href="#main-content" class="bfc-skip-link">Skip to content</a>

    <aside id="bfc-navigation"
           popover="auto"
           aria-label="Application navigation"
           data-testid="bfc-sidebar"
           class="bfc-sidebar">
        <div data-testid="bfc-mobile-drawer" class="bfc-sidebar-content">
            <div class="flex items-center justify-between gap-3">
                <a href="{{ $scalpelsUrl }}"
                   data-testid="bfc-header-scalpels"
                   class="bfc-wordmark">
                    <img data-testid="app-shell-brand" src="{{ $wordmarkUrl }}" alt="Scalpels dashboard" width="480" height="138">
                </a>
                <button type="button"
                        popovertarget="bfc-navigation"
                        popovertargetaction="hide"
                        data-testid="bfc-mobile-drawer-close"
                        class="bfc-shell-control lg:hidden"
                        aria-label="Close navigation">
                    <span aria-hidden="true">&#215;</span>
                </button>
            </div>

            <nav aria-label="Scalpels" class="bfc-sidebar-links">
                <a href="{{ \Illuminate\Support\Facades\Route::has('bfc.dashboard') ? route('bfc.dashboard') : url('/') }}"
                   data-testid="bfc-header-app"
                   class="bfc-shell-link bfc-app-link">
                    <x-app-logo-icon class="bfc-app-icon shrink-0 object-contain" />
                    <span class="truncate">{{ $brand->displayName }}</span>
                </a>
            </nav>

            @if (isset($sidebar) && $sidebar->isNotEmpty())
                <div data-testid="bfc-sidebar-slot" class="bfc-sidebar-slot">{{ $sidebar }}</div>
            @endif

            <div data-testid="bfc-sidebar-bottom" class="bfc-sidebar-bottom">
                <nav aria-label="Scalpels console" class="bfc-sidebar-links">
                    <a href="{{ $scalpelsDocsUrl }}"
                       data-testid="bfc-header-docs"
                       class="bfc-shell-link">
                        Documentation
                    </a>
                    @if ($manageUrl)
                        <a href="{{ $manageUrl }}"
                           data-testid="bfc-sidebar-manage"
                           class="bfc-shell-link">
                            Manage
                        </a>
                    @endif
                </nav>

                @if ($user)
                    <footer data-testid="bfc-sidebar-user" class="bfc-sidebar-user">
                        <button type="button"
                                popovertarget="bfc-user-menu"
                                data-testid="bfc-user-menu-button"
                                class="bfc-shell-control bfc-desktop-user-button">
                            <span class="truncate">{{ $user->name }}</span>
                            <span aria-hidden="true">&#8963;</span>
                        </button>
                    </footer>
                @endif
            </div>
        </div>
    </aside>

    <header data-testid="bfc-mobile-header" class="bfc-mobile-header">
        <button type="button"
                popovertarget="bfc-navigation"
                popovertargetaction="show"
                data-testid="bfc-mobile-drawer-open"
                class="bfc-shell-control"
                aria-label="Open navigation">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="size-5" aria-hidden="true">
                <path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16" />
            </svg>
        </button>
        <a href="{{ \Illuminate\Support\Facades\Route::has('bfc.dashboard') ? route('bfc.dashboard') : url('/') }}" class="bfc-shell-link min-w-0">
            <x-app-logo-icon class="size-6 shrink-0 object-contain" />
            <span class="truncate">{{ $brand->displayName }}</span>
        </a>
        @if ($user)
            <button type="button"
                    popovertarget="bfc-user-menu"
                    data-testid="bfc-mobile-user-menu-button"
                    class="bfc-shell-control bfc-mobile-user-button">
                <span class="truncate">{{ $user->name }}</span>
            </button>
        @endif
    </header>

    <main>
        <div id="main-content" data-testid="bfc-main" class="bfc-main" tabindex="-1">
            {{ $slot }}
        </div>
    </main>

    @if ($user)
        @include('bfc::shell.user-menu')
    @endif

    @persist('toast')
        <flux:toast.group>
            <flux:toast />
        </flux:toast.group>
    @endpersist

    @stack('scripts')
    @fluxScripts
</body>
</html>
