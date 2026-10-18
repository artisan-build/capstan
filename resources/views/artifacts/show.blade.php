@push('head')
        <meta name="robots" content="noindex, nofollow, noarchive">
@endpush

<x-app-shell :title="__('Artifact :id', ['id' => $artifact->id])">
        <main data-testid="artifact-viewer" class="mx-auto flex min-h-screen w-full max-w-7xl flex-col gap-4 p-4 sm:p-6">
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
</x-app-shell>
