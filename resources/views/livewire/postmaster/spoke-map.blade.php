<section class="w-full space-y-6" wire:poll.30s>
    <div>
        <flux:heading size="xl">{{ __('Postmaster map') }}</flux:heading>
        <flux:subheading>{{ __('Registered CLI installations and their current routing health.') }}</flux:subheading>
    </div>

    @if ($spokes->isEmpty())
        <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-5 py-8 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('No spokes have registered yet.') }}</flux:text>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($spokes as $spoke)
                    <div
                        class="grid gap-3 px-4 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-5"
                        data-spoke-id="{{ $spoke['id'] }}"
                        data-status="{{ $spoke['status']->value }}"
                        data-inbox-count="{{ $spoke['inboxes_count'] }}"
                        wire:key="spoke-{{ $spoke['id'] }}"
                    >
                        <div class="flex min-w-0 items-start gap-3">
                            <span
                                @class([
                                    'mt-1.5 size-2.5 shrink-0 rounded-full ring-4',
                                    $spoke['status']->dotClasses(),
                                ])
                                role="img"
                                aria-label="{{ $spoke['status']->label() }}"
                            ></span>

                            <div class="min-w-0">
                                <flux:heading size="lg" class="truncate">{{ $spoke['name'] }}</flux:heading>
                                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ __('Owned by :name', ['name' => $spoke['owner_name']]) }}
                                </flux:text>
                            </div>
                        </div>

                        <div class="grid gap-1 text-left sm:text-right">
                            <flux:text class="text-sm text-zinc-700 dark:text-zinc-300">
                                {{ __('Last polled :time', [
                                    'time' => $spoke['last_polled_at']?->diffForHumans() ?? __('Never'),
                                ]) }}
                            </flux:text>
                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ __('Inboxes: :count', ['count' => $spoke['inboxes_count']]) }}
                                <span aria-hidden="true">&middot;</span>
                                {{ __('Probe: :state', [
                                    'state' => $spoke['probe_status']->label(),
                                ]) }}
                            </flux:text>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</section>
