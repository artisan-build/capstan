<section class="w-full space-y-6" wire:poll.30s>
    <div>
        <flux:heading size="xl">{{ __('Postmaster map') }}</flux:heading>
        <flux:subheading>{{ __('Registered CLI installations and their current routing health.') }}</flux:subheading>
    </div>

    @if ($canOnboard)
        <div class="space-y-4 rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-900" data-onboarding-panel>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <flux:heading size="lg">{{ __('Connect a local agent') }}</flux:heading>
                    <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                        {{ __('Generate a short-lived installer for this server, then paste it into a shell.') }}
                    </flux:text>
                </div>

                @if ($onboardingSnippet === null)
                    <flux:button type="button" size="sm" wire:click="generateOnboardingSnippet" wire:loading.attr="disabled">
                        {{ __('Generate snippet') }}
                    </flux:button>
                @endif
            </div>

            @if ($onboardingSnippet !== null && $onboardingExpiresAt !== null)
                <div
                    class="space-y-4"
                    data-onboarding-expires-at="{{ $onboardingExpiresAt }}"
                    x-data="{
                        copyState: 'idle',
                        remaining: Math.max(0, {{ $onboardingExpiresAt }} - Math.floor(Date.now() / 1000)),
                        copySnippet() {
                            if (! navigator.clipboard) {
                                this.copyState = 'failed';
                                return;
                            }

                            navigator.clipboard.writeText(this.$refs.snippet.textContent)
                                .then(() => this.copyState = 'copied')
                                .catch(() => this.copyState = 'failed');
                        },
                    }"
                    x-init="setInterval(() => remaining = Math.max(0, remaining - 1), 1000)"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:button type="button" size="sm" x-on:click="copySnippet">
                            {{ __('Copy') }}
                        </flux:button>
                        <flux:button type="button" size="sm" variant="ghost" wire:click="generateOnboardingSnippet" wire:loading.attr="disabled">
                            {{ __('New code') }}
                        </flux:button>
                        <flux:text x-show="copyState === 'copied'" x-cloak class="text-sm text-green-600 dark:text-green-400">
                            {{ __('Copied.') }}
                        </flux:text>
                        <flux:text x-show="copyState === 'failed'" x-cloak class="text-sm text-red-600 dark:text-red-400">
                            {{ __('Copy failed. Select the snippet and copy it manually.') }}
                        </flux:text>
                    </div>

                    <pre x-ref="snippet" class="max-h-80 overflow-auto rounded-lg bg-zinc-950 p-4 text-xs leading-5 text-zinc-100" data-onboarding-snippet><code>{{ $onboardingSnippet }}</code></pre>

                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                        <span x-show="remaining > 0">{{ __('Expires in') }} <span x-text="remaining"></span> {{ __('seconds.') }}</span>
                        <span x-show="remaining === 0" x-cloak>{{ __('This code has expired. Generate a new snippet.') }}</span>
                        {{ __('The resulting token is written only to your local Capstan config directory.') }}
                    </flux:text>
                </div>
            @endif

        </div>
    @endif

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
