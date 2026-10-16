<?php

namespace App\Support;

use App\Features\Artifacts;
use Laravel\Pennant\Feature;

/**
 * The applied brand as Capstan's own chrome and notification mail may use it:
 * the display name, accent colours and isolated-origin image URLs, nothing
 * else. Header/footer HTML, custom CSS, tone notes and drafts never leave
 * BrandKitService through here, so they cannot reach the app origin or mail.
 */
final readonly class AppBrand
{
    public function __construct(
        private BrandKitService $brands,
        private ArtifactRenderOrigin $origin,
    ) {}

    public function current(): AppBrandValues
    {
        if (! Feature::active(Artifacts::class)) {
            return $this->fallback();
        }

        $state = $this->brands->readCurrentState();
        if ($state['current_version'] === null) {
            return $this->fallback();
        }

        $current = $state['current'];

        return new AppBrandValues(
            displayName: $current['display_name'],
            accentLight: $current['colours']['light']['accent'],
            accentDark: $current['colours']['dark']['accent'],
            markUrl: $this->assetUrl($current['assets']['mark']),
            logoLightUrl: $this->assetUrl($current['assets']['logo_light']),
            logoDarkUrl: $this->assetUrl($current['assets']['logo_dark'] ?? $current['assets']['logo_light']),
        );
    }

    private function fallback(): AppBrandValues
    {
        return new AppBrandValues(
            displayName: (string) config('app.name', 'Capstan'),
            accentLight: null,
            accentDark: null,
            markUrl: null,
            logoLightUrl: null,
            logoDarkUrl: null,
        );
    }

    private function assetUrl(mixed $id): ?string
    {
        return is_string($id) && $this->origin->isConfigured() ? $this->origin->brandAssetUrl($id) : null;
    }
}
