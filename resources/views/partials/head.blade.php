@inject('appBrand', \App\Support\AppBrand::class)
@php($brand = $appBrand->current())
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.$brand->displayName : $brand->displayName }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@if ($brand->hasAccent())
    <style data-testid="app-brand-accent">
        :root { --color-accent: {{ $brand->accentLight }}; --color-accent-content: {{ $brand->accentLight }}; --color-accent-foreground: {{ \App\Support\AppBrandValues::foregroundFor($brand->accentLight) }}; }
        .dark { --color-accent: {{ $brand->accentDark }}; --color-accent-content: {{ $brand->accentDark }}; --color-accent-foreground: {{ \App\Support\AppBrandValues::foregroundFor($brand->accentDark) }}; }
    </style>
@endif
@fluxAppearance
