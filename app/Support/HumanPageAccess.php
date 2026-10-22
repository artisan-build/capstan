<?php

namespace App\Support;

use ArtisanBuild\BuiltForCloud\Http\Middleware\EnsureUserIsAuthenticated;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final readonly class HumanPageAccess
{
    public function __construct(private EnsureUserIsAuthenticated $admission) {}

    /**
     * The admission gate's own answer for this request, in its three shapes:
     * the admitted local human, the redirect that sends an unauthenticated
     * browser into sign-in, or null for a caller the gate refused.
     *
     * The gate builds that redirect with THIS request's URI as its intended
     * destination, so handing it back — rather than collapsing it into an
     * opaque not-found — is what returns a deep link's visitor to the URL
     * they asked for once they have signed in.
     */
    public function admit(Request $request): User|RedirectResponse|null
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

        if ($result === $marker) {
            return $user instanceof User ? $user : null;
        }

        return $result instanceof RedirectResponse ? $result : null;
    }

    public function admittedUser(Request $request): ?User
    {
        $admitted = $this->admit($request);

        return $admitted instanceof User ? $admitted : null;
    }
}
