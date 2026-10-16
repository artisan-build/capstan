<?php

namespace App\Http\Middleware;

use App\Support\ArtifactRenderOrigin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RenderOriginIsolation
{
    /**
     * The only paths reachable on the render origin: isolated HTML blobs and
     * the health probe. Everything else on that host does not exist (D22).
     */
    private const array ALLOWED_PATTERNS = ['up', 'artifacts/*/content', 'brand-assets/*', 'pages/*/versions/*/content', 'page-shares/*/content'];

    public function __construct(private readonly ArtifactRenderOrigin $origin) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->origin->isConfigured() || $request->getHost() !== $this->origin->renderHost()) {
            return $next($request);
        }

        if (! $request->is(...self::ALLOWED_PATTERNS)) {
            return response('', 404)->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }

        $response = $next($request);

        // Defense in depth: the render origin must stay cookieless even if a
        // future route regresses into a session-bearing middleware stack.
        $response->headers->remove('Set-Cookie');

        return $response;
    }
}
