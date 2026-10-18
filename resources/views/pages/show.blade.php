@push('head')
        <meta name="robots" content="noindex, nofollow, noarchive">
        <style>
            .page-viewer-chrome { color: var(--color-clay-ink, #29231d); }
            .page-viewer-muted { color: var(--color-clay-muted, #75695d); }
            .page-viewer-panel { background: var(--color-clay-surface, #fffdf8); border-color: var(--color-clay-sand, #e7dccb); }
            .page-viewer-input { background: var(--color-clay-paper, #faf3e8); border: 1px solid var(--color-clay-edge, #cbbba6); color: var(--color-clay-ink, #29231d); color-scheme: light; }
            .page-viewer-input::placeholder { color: var(--color-clay-muted, #75695d); }
            .page-viewer-action { background: var(--color-accent); border: 1px solid var(--color-accent); color: var(--color-accent-foreground); text-decoration: none; }
            .page-viewer-secondary-action, .page-viewer-version-idle { background: var(--color-clay-slip, #f3eadf); color: var(--color-clay-ink, #29231d); }
            .page-viewer-version-current { background: var(--color-accent); color: var(--color-accent-foreground); }
            .page-viewer-share-record { background: var(--color-clay-paper, #faf3e8); border-color: var(--color-clay-sand, #e7dccb); }
            .page-viewer-link { color: var(--color-accent-content); overflow-wrap: anywhere; }
            .page-viewer-frame { background: var(--color-clay-surface, #fffdf8); border-color: var(--color-clay-edge, #cbbba6); }
        </style>
@endpush

<x-layouts::app :title="$page->title">
        <main data-testid="page-viewer" class="page-viewer-chrome mx-auto flex min-h-screen w-full max-w-7xl flex-col gap-4 p-4 sm:p-6">
            <header class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold">{{ $page->title }}</h1>
                    @if ($page->description !== null && $page->description !== '')
                        <p class="page-viewer-muted mt-1 text-sm">{{ $page->description }}</p>
                    @endif
                </div>
            </header>

            @if ($isOwner)
                <section data-testid="page-owner-metadata" class="page-viewer-panel rounded-xl border p-4">
                    <dl class="grid gap-3 text-sm sm:grid-cols-3">
                        <div>
                            <dt class="page-viewer-muted">Layout</dt>
                            <dd>{{ $page->layout->value }}</dd>
                        </div>
                        <div>
                            <dt class="page-viewer-muted">Current version</dt>
                            <dd>{{ $page->latestVersion?->version_number }}</dd>
                        </div>
                        <div>
                            <dt class="page-viewer-muted">Updated</dt>
                            <dd>
                                <time datetime="{{ $page->updated_at->toIso8601String() }}">{{ $page->updated_at->toIso8601String() }}</time>
                            </dd>
                        </div>
                    </dl>
                </section>

                <section data-testid="page-refresh-recipe" class="page-viewer-panel rounded-xl border p-4">
                    <h2 class="font-medium">Refresh recipe</h2>
                    <p class="page-viewer-muted mt-2 whitespace-pre-wrap text-sm">{{ $selectedVersion->refresh_recipe }}</p>
                </section>

                <section data-testid="page-version-history" class="page-viewer-panel rounded-xl border p-4">
                    <h2 class="font-medium">Version history</h2>
                    <nav aria-label="Page versions" class="mt-3 flex flex-wrap gap-2">
                        @foreach ($versions as $version)
                            <a
                                data-testid="page-version-record"
                                href="{{ route('pages.versions.show', ['page' => $page, 'version' => $version]) }}"
                                @class([
                                    'rounded px-2 py-1 text-sm',
                                    'page-viewer-version-current' => $version->is($selectedVersion),
                                    'page-viewer-version-idle' => ! $version->is($selectedVersion),
                                ])
                            >Version {{ $version->version_number }}</a>
                        @endforeach
                    </nav>
                    {{ $versions->links() }}
                </section>

                <section data-testid="page-sharing" id="page-sharing" class="page-viewer-panel rounded-xl border p-4">
                    <h2 class="mb-3 font-medium">Share</h2>
                    <div class="grid gap-4 lg:grid-cols-3">
                        <form method="POST" action="{{ route('pages.shares.store', ['page' => $page]) }}" class="space-y-2">
                            @csrf
                            <input type="hidden" name="kind" value="internal">
                            <label for="internal-email" class="block text-sm">Internal user email</label>
                            <input id="internal-email" type="email" name="internal_email" required class="page-viewer-input w-full rounded px-3 py-2">
                            <button type="submit" class="page-viewer-action rounded px-3 py-2 text-sm">Share internally</button>
                        </form>
                        <form method="POST" action="{{ route('pages.shares.store', ['page' => $page]) }}" class="space-y-2">
                            @csrf
                            <input type="hidden" name="kind" value="external">
                            <label for="external-email" class="block text-sm">External recipient email</label>
                            <input id="external-email" type="email" name="recipient_email" required class="page-viewer-input w-full rounded px-3 py-2">
                            <label for="external-expiry" class="block text-sm">Expiry (optional)</label>
                            <input id="external-expiry" type="datetime-local" name="expires_at" class="page-viewer-input w-full rounded px-3 py-2">
                            <button type="submit" class="page-viewer-action rounded px-3 py-2 text-sm">Email share</button>
                        </form>
                        <form method="POST" action="{{ route('pages.shares.store', ['page' => $page]) }}" class="space-y-2">
                            @csrf
                            <input type="hidden" name="kind" value="link">
                            <label for="link-expiry" class="block text-sm">Link expiry (optional)</label>
                            <input id="link-expiry" type="datetime-local" name="expires_at" class="page-viewer-input w-full rounded px-3 py-2">
                            <button type="submit" class="page-viewer-action rounded px-3 py-2 text-sm">Create link</button>
                        </form>
                    </div>

                    <div data-testid="page-shares" class="mt-4 space-y-2">
                        @foreach ($shares as $share)
                            <article data-testid="page-share-record" class="page-viewer-share-record rounded border p-3 text-sm">
                                <p>{{ $share->kind->value }}</p>
                                @if ($share->internalRecipient !== null)
                                    <p>{{ $share->internalRecipient->email }}</p>
                                @elseif ($share->recipient_email !== null)
                                    <p>{{ $share->recipient_email }}</p>
                                @endif
                                @if ($share->expires_at !== null)
                                    <time datetime="{{ $share->expires_at->toIso8601String() }}">{{ $share->expires_at->toIso8601String() }}</time>
                                @endif
                                <p>
                                    @if ($share->revoked_at !== null)
                                        revoked
                                    @elseif ($share->expires_at !== null && $share->expires_at->isPast())
                                        expired
                                    @else
                                        active
                                    @endif
                                </p>
                                @if ($shareUrls->get($share->id) !== null)
                                    <a class="page-viewer-link" href="{{ $shareUrls->get($share->id) }}" rel="nofollow">{{ $shareUrls->get($share->id) }}</a>
                                @endif
                                @if ($share->revoked_at === null)
                                    <form method="POST" action="{{ route('pages.shares.destroy', ['page' => $page, 'share' => $share]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="page-viewer-secondary-action rounded px-2 py-1">Revoke</button>
                                    </form>
                                @endif
                            </article>
                        @endforeach
                        {{ $shares->links() }}
                    </div>
                </section>

                <section data-testid="page-export">
                    <a href="{{ route('pages.export', ['page' => $page]) }}" class="page-viewer-action inline-flex rounded px-3 py-2 text-sm">Export HTML</a>
                </section>

                <section data-testid="page-delete">
                    <form method="POST" action="{{ route('pages.destroy', ['page' => $page]) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="rounded bg-red-700 px-3 py-2 text-sm text-white">Delete page</button>
                    </form>
                </section>
            @endif

            <iframe
                title="{{ $page->title }} version {{ $selectedVersion->version_number }}"
                src="{{ $contentUrl }}"
                sandbox="allow-scripts"
                referrerpolicy="no-referrer"
                class="page-viewer-frame min-h-[75vh] w-full flex-1 rounded-xl border"
            ></iframe>
        </main>
</x-layouts::app>
