<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="robots" content="noindex, nofollow, noarchive">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Artifact {{ $artifact->id }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-neutral-950 text-neutral-100">
        <main class="mx-auto flex min-h-screen w-full max-w-7xl flex-col gap-4 p-4 sm:p-6">
            <header class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-sm uppercase tracking-wide text-neutral-400">Capstan artifact</p>
                    <h1 class="text-xl font-semibold">Artifact {{ $artifact->id }}</h1>
                </div>
            </header>

            <iframe
                title="Artifact {{ $artifact->id }} content"
                src="{{ $contentUrl }}"
                sandbox="allow-scripts"
                referrerpolicy="no-referrer"
                class="min-h-[75vh] w-full flex-1 rounded-xl border border-neutral-800 bg-white"
            ></iframe>
        </main>
    </body>
</html>
