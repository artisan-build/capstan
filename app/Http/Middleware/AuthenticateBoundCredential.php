<?php

namespace App\Http\Middleware;

use App\Http\ApiError;
use ArtisanBuild\BuiltForCloud\BoundBearerCredential;
use ArtisanBuild\BuiltForCloud\BoundBearerCredentialAuthenticator;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthenticateBoundCredential
{
    private const string ATTRIBUTE = self::class;

    public function __construct(
        private BoundBearerCredentialAuthenticator $credentials,
    ) {}

    public function handle(Request $request, Closure $next, string $appPurpose): Response
    {
        $credential = $this->credentials->authenticate($request, $appPurpose);

        if ($credential === null || $credential->userId === null) {
            return ApiError::response(401, 'unauthenticated', 'Unauthenticated.');
        }

        $request->attributes->set(self::ATTRIBUTE, $credential);

        return $next($request);
    }

    public static function credential(Request $request): BoundBearerCredential
    {
        $credential = $request->attributes->get(self::ATTRIBUTE);

        if (! $credential instanceof BoundBearerCredential) {
            throw new LogicException('The request does not contain an authenticated bound credential.');
        }

        return $credential;
    }

    public static function actorId(Request $request): string
    {
        $actorId = self::credential($request)->userId;

        if ($actorId === null) {
            throw new LogicException('The authenticated bound credential does not identify an actor.');
        }

        return $actorId;
    }
}
