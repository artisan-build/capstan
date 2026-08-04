<?php

namespace App\Http\Middleware;

use App\Http\ApiActor;
use App\Http\ApiError;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class ResolveApiActor
{
    public const string ATTRIBUTE = 'api_actor';

    private const int LAST_USED_THROTTLE_SECONDS = 60;

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return ApiError::unauthenticated();
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if ($accessToken === null || $this->tokenIsExpired($accessToken)) {
            return ApiError::unauthenticated();
        }

        $user = $accessToken->tokenable;

        if (! $user instanceof User) {
            return ApiError::unauthenticated();
        }

        $user->withAccessToken($accessToken);
        $this->stampLastUsed($accessToken);
        Auth::setUser($user);
        $request->attributes->set(self::ATTRIBUTE, ApiActor::user($user->getKey()));

        return $next($request);
    }

    public static function actor(Request $request): ?ApiActor
    {
        $actor = $request->attributes->get(self::ATTRIBUTE);

        return $actor instanceof ApiActor ? $actor : null;
    }

    private function tokenIsExpired(PersonalAccessToken $accessToken): bool
    {
        if ($accessToken->expires_at !== null && $accessToken->expires_at->isPast()) {
            return true;
        }

        $expiration = config('sanctum.expiration');

        if (is_numeric($expiration) && $accessToken->created_at !== null) {
            return $accessToken->created_at->addMinutes((int) $expiration)->isPast();
        }

        return false;
    }

    private function stampLastUsed(PersonalAccessToken $accessToken): void
    {
        if ($accessToken->last_used_at !== null && $accessToken->last_used_at->gt(now()->subSeconds(self::LAST_USED_THROTTLE_SECONDS))) {
            return;
        }

        $accessToken->forceFill(['last_used_at' => now()])->save();
    }
}
