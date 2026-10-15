<x-layouts::app :title="__('Pages')">
    <main data-testid="pages-library" class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
        <header>
            <flux:heading size="xl">{{ __('Pages') }}</flux:heading>
        </header>

        <section data-testid="pages-my-section" class="space-y-3">
            <flux:heading size="lg">{{ __('My pages') }}</flux:heading>

            @forelse ($pages as $page)
                <article data-testid="pages-my-row" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <a href="{{ route('pages.show', ['page' => $page]) }}" class="font-medium hover:underline">
                                {{ $page->title }}
                            </a>
                            <time datetime="{{ $page->updated_at->toIso8601String() }}" class="mt-1 block text-sm text-zinc-500 dark:text-zinc-400">
                                {{ $page->updated_at->diffForHumans() }}
                            </time>
                        </div>
                        <span class="text-sm text-zinc-600 dark:text-zinc-300">
                            {{ $page->getAttribute('has_active_shares') ? __('Shared') : __('Private') }}
                        </span>
                    </div>
                </article>
            @empty
                <p data-testid="pages-my-empty" class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No pages yet.') }}</p>
            @endforelse

            {{ $pages->links() }}
        </section>

        <section data-testid="pages-shared-section" class="space-y-3">
            <flux:heading size="lg">{{ __('Shared with me') }}</flux:heading>

            @forelse ($sharedPages as $page)
                <article data-testid="pages-shared-row" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <a href="{{ route('pages.show', ['page' => $page]) }}" class="font-medium hover:underline">
                                {{ $page->title }}
                            </a>
                            <time datetime="{{ $page->updated_at->toIso8601String() }}" class="mt-1 block text-sm text-zinc-500 dark:text-zinc-400">
                                {{ $page->updated_at->diffForHumans() }}
                            </time>
                        </div>
                        <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('Shared with you') }}</span>
                    </div>
                </article>
            @empty
                <p data-testid="pages-shared-empty" class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No shared pages.') }}</p>
            @endforelse

            {{ $sharedPages->links() }}
        </section>
    </main>
</x-layouts::app>
