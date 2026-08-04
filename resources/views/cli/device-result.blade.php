<x-layouts::auth :title="__('Capstan CLI')">
    <div class="flex flex-col gap-4 text-center">
        <flux:icon :name="$success ? 'check-circle' : 'x-circle'" class="mx-auto size-12 {{ $success ? 'text-green-500' : 'text-red-500' }}" />

        <flux:heading size="xl">
            {{ $success ? __('Device authorized') : __('Not authorized') }}
        </flux:heading>

        <flux:subheading>{{ $message }}</flux:subheading>
    </div>
</x-layouts::auth>
