<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Token settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Personal access tokens')" :subheading="__('Review and revoke CLI tokens signed in to your account')">
        @if ($tokens->isEmpty())
            <flux:text class="text-zinc-500">{{ __('No personal access tokens.') }}</flux:text>
        @else
            <div class="flex flex-col divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($tokens as $token)
                    <div class="flex items-center justify-between gap-4 py-3">
                        <div class="flex min-w-0 flex-col">
                            <flux:text class="truncate font-medium">{{ $token->name }}</flux:text>
                            <flux:text class="text-sm text-zinc-500">
                                {{ __('Created :date', ['date' => $token->created_at?->toDayDateTimeString()]) }}
                                &middot;
                                {{ $token->last_used_at !== null
                                    ? __('Last used :date', ['date' => $token->last_used_at->toDayDateTimeString()])
                                    : __('Never used') }}
                            </flux:text>
                        </div>

                        <flux:button
                            size="sm"
                            variant="ghost"
                            wire:click="revoke({{ $token->id }})"
                            wire:confirm="{{ __('Revoke this token? Any CLI sessions using it will stop working immediately.') }}"
                        >
                            {{ __('Revoke') }}
                        </flux:button>
                    </div>
                @endforeach
            </div>
        @endif
    </x-pages::settings.layout>
</section>
