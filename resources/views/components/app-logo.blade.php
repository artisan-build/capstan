@props([
    'sidebar' => false,
])

@inject('appBrand', \App\Support\AppBrand::class)
@php($brand = $appBrand->current())
@php($logoClass = 'flex aspect-square size-8 items-center justify-center rounded-md'.($brand->iconLightUrl() === null ? ' bg-accent-content text-accent-foreground' : ''))
@php($iconClass = $brand->iconLightUrl() === null ? 'size-5 fill-current text-white dark:text-black' : 'size-8')

@if($sidebar)
    <flux:sidebar.brand :name="$brand->displayName" {{ $attributes }}>
        <x-slot name="logo" :class="$logoClass">
            <x-app-logo-icon :class="$iconClass" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="$brand->displayName" {{ $attributes }}>
        <x-slot name="logo" :class="$logoClass">
            <x-app-logo-icon :class="$iconClass" />
        </x-slot>
    </flux:brand>
@endif
