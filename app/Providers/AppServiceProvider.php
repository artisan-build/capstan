<?php

namespace App\Providers;

use App\Http\Middleware\ResolveApiActor;
use App\Support\PostmasterClock;
use App\Support\ServerIdentity;
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

        RateLimiter::for('cli-device', fn (Request $request): Limit => Limit::perMinute(15)->by($request->ip() ?: 'unknown'));

        RateLimiter::for('cli-verify', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(10)->by($user?->getAuthIdentifier() ?? $request->ip() ?? 'unknown');
        });

        RateLimiter::for('api', function (Request $request): Limit {
            $actor = ResolveApiActor::actor($request);

            return Limit::perMinute(60)->by($actor?->userId !== null ? 'user:'.$actor->userId : 'ip:'.($request->ip() ?: 'unknown'));
        });
    }
}
