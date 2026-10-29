@if ($brand->hasAccent())
    <style data-testid="app-shell-accent">
        .bfc-body { --color-accent: {{ $brand->accentLight }} !important; --color-accent-content: {{ $brand->accentLight }} !important; --color-accent-foreground: {{ \App\Support\AppBrandValues::foregroundFor($brand->accentLight) }} !important; }
        .dark .bfc-body { --color-accent: {{ $brand->accentDark }} !important; --color-accent-content: {{ $brand->accentDark }} !important; --color-accent-foreground: {{ \App\Support\AppBrandValues::foregroundFor($brand->accentDark) }} !important; }
        .bfc-body .bfc-auth button[type="submit"] { background: var(--color-accent); border-color: var(--color-accent); color: var(--color-accent-foreground); }
        .bfc-body .bfc-link { color: var(--color-accent-content); }
    </style>
@endif
