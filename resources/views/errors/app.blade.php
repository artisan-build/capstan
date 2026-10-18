<x-app-shell :title="$title">
    <section data-testid="error-page" class="bfc-auth app-shell-error">
        <p class="app-shell-error-code">{{ $status }}</p>
        <h1 class="app-shell-error-title">{{ $title }}</h1>
    </section>
</x-app-shell>
