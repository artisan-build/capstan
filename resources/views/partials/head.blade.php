@inject('appBrand', \App\Support\AppBrand::class)
@php
    $brand = rescue(
        fn () => $appBrand->current(),
        new \App\Support\AppBrandValues(
            displayName: (string) config('app.name', 'Capstan'),
            accentLight: null,
            accentDark: null,
            markUrl: null,
            logoLightUrl: null,
            logoDarkUrl: null,
        ),
        false,
    );
@endphp
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.$brand->displayName : $brand->displayName }}
</title>

<link rel="icon" href="{{ $brand->iconLightUrl() ?? config('built-for-cloud.manifest.icon') }}">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@if ($brand->hasAccent())
    <style data-testid="app-brand-accent">
        :root { --color-accent: {{ $brand->accentLight }}; --color-accent-content: {{ $brand->accentLight }}; --color-accent-foreground: {{ \App\Support\AppBrandValues::foregroundFor($brand->accentLight) }}; }
        .dark { --color-accent: {{ $brand->accentDark }}; --color-accent-content: {{ $brand->accentDark }}; --color-accent-foreground: {{ \App\Support\AppBrandValues::foregroundFor($brand->accentDark) }}; }
    </style>
@endif
@fluxAppearance
