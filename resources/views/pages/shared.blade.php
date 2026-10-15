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
        <main data-testid="page-shared-viewer" class="mx-auto flex min-h-screen w-full max-w-7xl flex-col gap-4 p-4 sm:p-6">
            <header>
                <h1 class="text-xl font-semibold">{{ $page->title }}</h1>
                @if ($page->description !== null && $page->description !== '')
                    <p class="mt-1 text-sm text-neutral-300">{{ $page->description }}</p>
                @endif
            </header>
            <iframe
                title="{{ $page->title }}"
                src="{{ $contentUrl }}"
                sandbox="allow-scripts"
                referrerpolicy="no-referrer"
                class="min-h-[75vh] w-full flex-1 rounded-xl border border-neutral-800 bg-white"
            ></iframe>
        </main>
    </body>
</html>
