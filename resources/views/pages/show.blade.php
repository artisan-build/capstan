<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="robots" content="noindex, nofollow, noarchive">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $page->title }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-neutral-950 text-neutral-100">
        <main data-testid="page-viewer" class="mx-auto flex min-h-screen w-full max-w-7xl flex-col gap-4 p-4 sm:p-6">
            <header class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold">{{ $page->title }}</h1>
                    @if ($page->description !== null && $page->description !== '')
                        <p class="mt-1 text-sm text-neutral-300">{{ $page->description }}</p>
                    @endif
                    <p class="mt-2 whitespace-pre-wrap text-xs text-neutral-400">{{ $selectedVersion->refresh_recipe }}</p>
                </div>
                <nav aria-label="Page versions" class="flex flex-wrap gap-2">
                    @foreach ($page->versions as $version)
                        <a
                            href="{{ route('pages.versions.show', ['page' => $page, 'version' => $version]) }}"
                            @class([
                                'rounded px-2 py-1 text-sm',
                                'bg-white text-neutral-950' => $version->is($selectedVersion),
                                'bg-neutral-800 text-neutral-100' => ! $version->is($selectedVersion),
                            ])
                        >Version {{ $version->version_number }}</a>
                    @endforeach
                </nav>
            </header>

            <iframe
                title="{{ $page->title }} version {{ $selectedVersion->version_number }}"
                src="{{ $contentUrl }}"
                sandbox="allow-scripts"
                referrerpolicy="no-referrer"
                class="min-h-[75vh] w-full flex-1 rounded-xl border border-neutral-800 bg-white"
            ></iframe>
        </main>
    </body>
</html>
