{{--
    Built for Cloud's default shell (bfc::layout), copied so the applied brand
    can reach it through App\View\BrandedBuiltForCloudLayout. Only the brand
    points differ from the package view: the favicon, the app icon and name in
    the header, and the accent tokens. Without an applied kit they fall back to
    the configured app name, Capstan's own favicon and its built-in SVG mark.
    The Scalpels wordmark and navigation are untouched.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#faf3e8">
    <title>{{ $title }}</title>
    <link rel="icon" href="{{ $brand->iconLightUrl() ?? '/favicon.svg' }}">
    @if ($stylesheetUrl)
        <link rel="stylesheet" href="{{ $stylesheetUrl }}">
    @endif
    @if ($brand->hasAccent())
        <style data-testid="bfc-brand-accent">
            .bfc-body { --color-accent: {{ $brand->accentLight }}; --color-accent-content: {{ $brand->accentLight }}; --color-accent-foreground: {{ \App\Support\AppBrandValues::foregroundFor($brand->accentLight) }}; }
            .dark .bfc-body { --color-accent: {{ $brand->accentDark }}; --color-accent-content: {{ $brand->accentDark }}; --color-accent-foreground: {{ \App\Support\AppBrandValues::foregroundFor($brand->accentDark) }}; }
        </style>
    @endif
    @stack('head')
</head>
<body data-testid="bfc-layout"
      class="bfc-body min-h-screen bg-clay-paper font-sans text-clay-soft antialiased
             [--color-white:var(--color-clay-surface)] [--color-zinc-50:var(--color-clay-surface)] [--color-zinc-100:var(--color-clay-slip)] [--color-zinc-200:var(--color-clay-sand)] [--color-zinc-300:var(--color-clay-edge)] [--color-zinc-400:var(--color-clay-dot)] [--color-zinc-500:var(--color-clay-brown)] [--color-zinc-600:var(--color-clay-soft)] [--color-zinc-700:var(--color-clay-soft)] [--color-zinc-800:var(--color-clay-ink)] [--color-zinc-900:var(--color-clay-ink)] [--color-zinc-950:var(--color-clay-ink)] [--color-accent:var(--color-clay-brown)] [--color-accent-content:var(--color-clay-iron)] [--color-accent-foreground:var(--color-clay-surface)]">
    <header data-testid="bfc-header"
            class="sticky top-0 z-50 bg-clay-paper/80 px-5 text-clay-ink backdrop-blur-md
                   [&_a]:focus-visible:outline-3 [&_a]:focus-visible:outline-offset-4 [&_a]:focus-visible:outline-clay-iron
                   sm:px-8">
        <div class="mx-auto flex max-w-6xl items-center gap-3 py-3
                    lg:py-4">
            <a href="{{ \ArtisanBuild\BuiltForCloud\View\Layout::SCALPELS_URL }}"
               data-testid="bfc-header-scalpels"
               class="inline-flex min-h-11 w-24 shrink-0 items-center rounded-xl
                      sm:w-32">
                <img src="{{ $wordmarkUrl }}"
                     alt="Scalpels dashboard"
                     width="480"
                     height="138"
                     class="block h-auto w-full">
            </a>

            <a href="{{ url('/') }}"
               data-testid="bfc-header-app"
               class="bfc-header-link min-w-0 text-clay-ink">
                <x-app-logo-icon class="size-6 shrink-0 object-contain fill-current" />
                <span class="truncate">
                    {{ $brand->displayName }}
                </span>
            </a>

            <span class="flex-1"
                  aria-hidden="true"></span>

            <nav class="hidden items-center gap-0.5 text-clay-soft
                        lg:flex"
                 aria-label="Scalpels">
                <a href="{{ \ArtisanBuild\BuiltForCloud\View\Layout::SCALPELS_DOCS_URL }}"
                   data-testid="bfc-header-docs"
                   class="bfc-header-link">
                    <span class="bfc-bead"
                          aria-hidden="true"></span>
                    Documentation
                </a>
            </nav>

            @if ($user)
                <button type="button"
                        popovertarget="bfc-user-menu"
                        data-testid="bfc-user-menu-button"
                        class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-full bg-clay-surface px-4 text-sm font-semibold text-clay-brown shadow-clay-inset [anchor-name:--bfc-user-menu]
                               hover:bg-clay-sand
                               focus-visible:outline-3 focus-visible:outline-offset-4 focus-visible:outline-clay-iron">
                    <span class="max-w-40 truncate">
                        {{ $user->name }}
                    </span>
                    <svg class="size-4 shrink-0"
                         viewBox="0 0 20 20"
                         fill="currentColor"
                         aria-hidden="true">
                        <path fill-rule="evenodd"
                              d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z"
                              clip-rule="evenodd" />
                    </svg>
                </button>

                <div id="bfc-user-menu"
                     popover
                     data-testid="bfc-user-menu"
                     class="bfc-user-menu">
                    <div class="grid px-2 py-1.5 text-start text-sm leading-tight">
                        <span class="truncate font-medium text-clay-ink">
                            {{ $user->name }}
                        </span>
                        <span class="truncate text-clay-muted">
                            {{ $user->email }}
                        </span>
                    </div>

                    <hr class="bfc-user-menu-separator">

                    <a href="{{ \ArtisanBuild\BuiltForCloud\View\Layout::SCALPELS_URL }}"
                       class="bfc-user-menu-item">
                        <svg viewBox="0 0 24 24"
                             fill="none"
                             stroke="currentColor"
                             stroke-width="1.5"
                             aria-hidden="true">
                            <path stroke-linecap="round"
                                  stroke-linejoin="round"
                                  d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
                        </svg>
                        Scalpels dashboard
                    </a>

                    <a href="{{ \ArtisanBuild\BuiltForCloud\View\Layout::SCALPELS_DOCS_URL }}"
                       class="bfc-user-menu-item
                              lg:hidden">
                        <svg viewBox="0 0 24 24"
                             fill="none"
                             stroke="currentColor"
                             stroke-width="1.5"
                             aria-hidden="true">
                            <path stroke-linecap="round"
                                  stroke-linejoin="round"
                                  d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                        </svg>
                        Documentation
                    </a>

                    @if ($logoutUrl)
                        <hr class="bfc-user-menu-separator">

                        <form method="POST"
                              action="{{ $logoutUrl }}"
                              data-testid="bfc-user-menu-logout">
                            @csrf
                            <button type="submit"
                                    class="bfc-user-menu-item">
                                <svg viewBox="0 0 24 24"
                                     fill="none"
                                     stroke="currentColor"
                                     stroke-width="1.5"
                                     aria-hidden="true">
                                    <path stroke-linecap="round"
                                          stroke-linejoin="round"
                                          d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" />
                                </svg>
                                Log out
                            </button>
                        </form>
                    @endif
                </div>
            @endif
        </div>
    </header>

<main>
    {{ $slot }}
</main>

@stack('scripts')
</body>
</html>
