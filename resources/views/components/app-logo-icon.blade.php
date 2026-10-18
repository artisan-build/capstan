@inject('appBrand', \App\Support\AppBrand::class)
@php($brand = $appBrand->current())
@if ($brand->iconLightUrl() !== null)
    @if ($brand->iconLightUrl() === $brand->iconDarkUrl())
        <img data-testid="app-brand-icon" src="{{ $brand->iconLightUrl() }}" alt="" referrerpolicy="no-referrer" {{ $attributes->class('object-contain') }} />
    @else
        <img data-testid="app-brand-icon" src="{{ $brand->iconLightUrl() }}" alt="" referrerpolicy="no-referrer" {{ $attributes->class('object-contain dark:hidden') }} />
        <img data-testid="app-brand-icon" src="{{ $brand->iconDarkUrl() }}" alt="" referrerpolicy="no-referrer" {{ $attributes->class('hidden object-contain dark:block') }} />
    @endif
@else
    <img
        data-testid="capstan-app-icon"
        src="{{ config('built-for-cloud.manifest.icon') }}"
        alt=""
        width="128"
        height="128"
        referrerpolicy="no-referrer"
        {{ $attributes->class('object-contain') }}
    />
@endif
