<?php

namespace App\Support;

use App\Models\Artifact;
use Illuminate\Support\Facades\URL;

class ArtifactRenderOrigin
{
    public function appOrigin(): string
    {
        return $this->originFromUrl((string) config('app.url'));
    }

    public function appHost(): string
    {
        return (string) parse_url($this->appOrigin(), PHP_URL_HOST);
    }

    public function renderOrigin(): string
    {
        $origin = config('capstan.artifacts.render_origin');

        return $this->originFromUrl((string) $origin);
    }

    public function isConfigured(): bool
    {
        $origin = config('capstan.artifacts.render_origin');

        return is_string($origin) && $origin !== '';
    }

    public function renderHost(): string
    {
        return (string) parse_url($this->renderOrigin(), PHP_URL_HOST);
    }

    public function renderHostFor(Artifact $artifact): string
    {
        // Path A uses one shared render subdomain. Keep this seam: Path B can
        // switch this resolver to a per-artifact host without changing data.
        return $this->renderHost();
    }

    public function signedContentUrl(Artifact $artifact): string
    {
        $ceiling = now()->addMinutes(5);
        $expiresAt = $artifact->expires_at && $artifact->expires_at->isBefore($ceiling)
            ? $artifact->expires_at
            : $ceiling;

        $path = URL::temporarySignedRoute(
            'artifacts.content',
            $expiresAt,
            ['artifact' => $artifact],
            false,
        );

        return $this->renderOrigin().$path;
    }

    public function signedViewerUrl(Artifact $artifact): string
    {
        $expiresAt = $artifact->expires_at && $artifact->expires_at->isFuture()
            ? $artifact->expires_at
            : now()->addHour();

        return URL::temporarySignedRoute(
            'artifacts.share',
            $expiresAt,
            ['artifact' => $artifact],
            true,
        );
    }

    public function contentSecurityPolicy(): string
    {
        return implode('; ', [
            "default-src 'none'",
            $this->sourceDirective('script-src', ["'unsafe-inline'"], 'script_src'),
            $this->sourceDirective('style-src', ["'unsafe-inline'"], 'style_src'),
            $this->sourceDirective('font-src', [], 'font_src'),
            $this->sourceDirective('img-src', ['data:'], 'img_src'),
            "connect-src 'none'",
            "form-action 'none'",
            "base-uri 'none'",
            'frame-ancestors '.$this->appOrigin(),
        ]);
    }

    /**
     * @param  list<string>  $defaults
     */
    private function sourceDirective(string $name, array $defaults, string $configKey): string
    {
        $configured = config('capstan.artifacts.csp_allowlist.'.$configKey, []);
        $sources = array_merge($defaults, is_array($configured) ? array_values($configured) : []);

        return $name.' '.(count($sources) > 0 ? implode(' ', $sources) : "'none'");
    }

    private function originFromUrl(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        $port = parse_url($url, PHP_URL_PORT);

        return $scheme.'://'.$host.($port ? ':'.$port : '');
    }
}
