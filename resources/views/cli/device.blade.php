<x-layouts::auth :title="__('Authorize this device')">
    <div class="flex flex-col gap-2 text-center">
        <flux:heading size="xl">{{ __('Authorize this device') }}</flux:heading>
        <flux:subheading>
            {{ __('Approving signs the Capstan CLI in on the device showing this code, with an identity token scoped to your account.') }}
        </flux:subheading>
    </div>

    <flux:callout variant="warning" icon="exclamation-triangle">
        <flux:callout.heading>{{ __('Only approve a code you started') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('Confirm the code below matches the one shown right now in your own terminal. Never approve a code someone else sent you; doing so would authorize their device with your account.') }}
        </flux:callout.text>
    </flux:callout>

    @if ($userCode !== '')
        <div class="flex flex-col items-center gap-1">
            <flux:text class="text-sm">{{ __('Code to confirm') }}</flux:text>
            <div class="rounded-lg border border-neutral-200 px-4 py-2 font-mono text-2xl tracking-widest dark:border-neutral-700" data-test="user-code">
                {{ $userCode }}
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('cli.device.verify') }}" class="flex flex-col gap-4">
        @csrf

        <flux:input
            name="user_code"
            :label="__('Device code')"
            type="text"
            required
            autofocus
            value="{{ $userCode }}"
            :placeholder="__('WDJB-MJHT')"
            :description="__('Re-type or confirm the code from your terminal before approving.')"
        />

        <flux:button type="submit" name="action" value="approve" variant="primary" class="w-full">
            {{ __('Yes, authorize this device') }}
        </flux:button>

        <flux:button type="submit" name="action" value="deny" variant="ghost" class="w-full">
            {{ __('Deny') }}
        </flux:button>
    </form>
</x-layouts::auth>
