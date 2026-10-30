<nav data-testid="app-shell-sidebar" class="bfc-sidebar-links" aria-label="{{ __('Platform') }}">
    <a class="bfc-shell-link" href="{{ route('bfc.dashboard') }}" @if (request()->routeIs('bfc.dashboard')) aria-current="page" @endif>{{ __('Dashboard') }}</a>
    <a class="bfc-shell-link" href="{{ route('postmaster.map') }}" @if (request()->routeIs('postmaster.*')) aria-current="page" @endif>{{ __('Postmaster') }}</a>
    <a data-testid="work-navigation" class="bfc-shell-link" href="{{ route('work.index') }}" @if (request()->routeIs('work.*')) aria-current="page" @endif>{{ __('Work') }}</a>
    @if (\Laravel\Pennant\Feature::active(\App\Features\Artifacts::class))
        <a data-testid="pages-navigation" class="bfc-shell-link" href="{{ route('pages.index') }}" @if (request()->routeIs('pages.*', 'artifacts.share')) aria-current="page" @endif>{{ __('Pages') }}</a>
        @if (\ArtisanBuild\BuiltForCloud\RolePolicy::canManageMembers($shellUser->role))
            <a data-testid="branding-navigation" class="bfc-shell-link" href="{{ route('branding.show') }}" @if (request()->routeIs('branding.*')) aria-current="page" @endif>{{ __('Branding') }}</a>
        @endif
    @endif
</nav>
