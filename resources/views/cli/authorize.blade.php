<x-layouts::auth :title="__('Authorize the Capstan CLI')">
    <div class="flex flex-col gap-2 text-center">
        <flux:heading size="xl">{{ __('Authorize the Capstan CLI') }}</flux:heading>
        <flux:subheading>
            {{ __('Authorize the Capstan CLI for :email?', ['email' => $email]) }}
        </flux:subheading>
    </div>

    <flux:callout variant="secondary" icon="command-line">
        <flux:callout.heading>{{ __('Callback address') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('Approving signs the CLI in on this computer with an identity token scoped to your account. The token will be sent to :callback.', ['callback' => $callbackAuthority]) }}
        </flux:callout.text>
    </flux:callout>

    <form method="POST" action="{{ route('cli.authorize') }}" class="flex flex-col gap-4">
        @csrf
        <input type="hidden" name="redirect_uri" value="{{ $redirectUri }}" />
        <input type="hidden" name="state" value="{{ $state }}" />
        <input type="hidden" name="label" value="{{ $label }}" />

        <flux:button type="submit" name="action" value="approve" variant="primary" class="w-full">
            {{ __('Approve') }}
        </flux:button>

        <flux:button type="submit" name="action" value="deny" variant="ghost" class="w-full">
            {{ __('Deny') }}
        </flux:button>
    </form>
</x-layouts::auth>
