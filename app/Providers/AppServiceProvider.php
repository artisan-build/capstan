<?php

namespace App\Providers;

use App\Http\Middleware\AuthenticateBoundCredential;
use App\Machine\TeamMemberships;
use App\Postmaster\DatabaseProbeFailureNotifier;
use App\Postmaster\ProbeFailureNotifier;
use App\Support\PostmasterClock;
use App\Support\ServerIdentity;
use App\Work\DatabaseWorkNotifier;
use App\Work\WorkNotifier;
use ArtisanBuild\BuiltForCloud\Contracts\IdentityContext;
use ArtisanBuild\BuiltForCloud\DomainIdentityContext;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ServerIdentity::class);
        $this->app->bind(ProbeFailureNotifier::class, DatabaseProbeFailureNotifier::class);
        $this->app->bind(WorkNotifier::class, DatabaseWorkNotifier::class);
        $this->app->bind(function (): IdentityContext {
            $user = auth()->user();

            abort_unless($user instanceof User, 401);

            return DomainIdentityContext::forUser($user, InstallationAuthority::current());
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        User::created(fn (User $user) => resolve(TeamMemberships::class)->joinDefault($user));
        $this->configureDefaults();

        // Fork-and-deploy footgun: a non-UTC APP_TIMEZONE breaks every envelope signature.
        PostmasterClock::assertUtc();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );

        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(60)->by('credential:'.AuthenticateBoundCredential::credential($request)->id));
        RateLimiter::for('orb', fn (Request $request): Limit => Limit::perMinute(60)->by('orb:'.($request->ip() ?? 'unknown')));
        RateLimiter::for('postmaster-install', fn (Request $request): Limit => Limit::perMinute(15)->by((string) $request->ip()));
    }
}
