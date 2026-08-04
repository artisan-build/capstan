<?php

namespace App\Http\Middleware;

use App\Support\ArtifactRenderOrigin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RenderOriginIsolation
{
    /**
     * The only paths reachable on the render origin: artifact blobs and the
     * health probe. Everything else on that host does not exist (D22).
     */
    private const ALLOWED_PATTERNS = ['up', 'artifacts/*/content'];

    public function __construct(private readonly ArtifactRenderOrigin $origin) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->origin->isConfigured() || $request->getHost() !== $this->origin->renderHost()) {
            return $next($request);
        }

        abort_unless($request->is(...self::ALLOWED_PATTERNS), 404);

        $response = $next($request);

        // Defense in depth: the render origin must stay cookieless even if a
        // future route regresses into a session-bearing middleware stack.
        $response->headers->remove('Set-Cookie');

        return $response;
    }
}
