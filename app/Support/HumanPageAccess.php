<?php

namespace App\Support;

use ArtisanBuild\BuiltForCloud\Http\Middleware\EnsureUserIsAuthenticated;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final readonly class HumanPageAccess
{
    public function __construct(private EnsureUserIsAuthenticated $admission) {}

    public function admittedUser(Request $request): ?User
    {
        $marker = response('', 204);

        try {
            $result = $this->admission->handle(
                $request,
                static fn (Request $request): Response => $marker,
            );
        } catch (HttpExceptionInterface) {
            return null;
        }

        $user = $request->user();

        return $result === $marker && $user instanceof User ? $user : null;
    }
}
