<?php

namespace App\Providers;

use App\Postmaster\LogProbeFailureNotifier;
use App\Postmaster\ProbeFailureNotifier;
use App\Support\PostmasterClock;
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
        $this->app->bind(ProbeFailureNotifier::class, LogProbeFailureNotifier::class);
        $this->app->bind(IdentityContext::class, function (): DomainIdentityContext {
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
        $this->configureDefaults();

        // Fork-and-deploy footgun: a non-UTC APP_TIMEZONE breaks every envelope signature.
        // Config, not Pennant's store, is the source of truth for the flag (D23).
        if (config('capstan.features.postmaster')) {
            PostmasterClock::assertUtc();
        }
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

        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(60)->by($request->ip() ?: 'unknown');
        });
    }
}
