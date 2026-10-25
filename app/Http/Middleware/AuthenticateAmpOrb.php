<?php

namespace App\Http\Middleware;

use App\Auth\AmpClaimBinder;
use App\Auth\AmpClaimContext;
use App\Auth\AmpTokenVerifier;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class AuthenticateAmpOrb
{
    public function __construct(
        private AmpTokenVerifier $tokens,
        private AmpClaimBinder $claims,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $authorization = $request->header('Authorization');
        if (! is_string($authorization)
            || preg_match('/\ABearer ([A-Za-z0-9._-]+)\z/D', $authorization, $match) !== 1) {
            return $this->denied();
        }

        try {
            $identity = $this->tokens->verify($match[1]);
            $request->attributes->set(AmpClaimContext::REQUEST_ATTRIBUTE, $this->claims->bind($identity));
        } catch (Throwable) {
            return $this->denied();
        }

        return $next($request);
    }

    private function denied(): JsonResponse
    {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }
}
