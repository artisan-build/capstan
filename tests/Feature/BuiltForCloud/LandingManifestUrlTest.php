<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\LandingManifest;

/*
|--------------------------------------------------------------------------
| Landing manifest URLs
|--------------------------------------------------------------------------
|
| The manifest is rendered to anonymous visitors on the landing page (the icon
| as an <img src>, the product URL as an <a href>), so every URL it carries has
| to resolve publicly. scalpels.app/catalog/* is the owner-only namespace and
| 302s to /login; the public namespace is /products/*. See issue #37.
|
| These assertions are deliberately deterministic: they pin the configured
| values and never call out to scalpels.app. Whether a public URL *stays*
| reachable is an operational property for an external monitor, not for the
| merge gate.
|
*/

/** @return array<string, string> */
function capstanManifestUrls(): array
{
    $manifest = LandingManifest::fromConfiguration();

    return [
        'icon' => $manifest->icon,
        'product_url' => $manifest->productUrl,
    ];
}

it('keeps every landing manifest URL out of the authenticated scalpels.app namespace', function (): void {
    foreach (capstanManifestUrls() as $field => $url) {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($host !== 'scalpels.app') {
            continue;
        }

        expect($path)->not->toStartWith(
            '/catalog',
            "The landing manifest [{$field}] URL [{$url}] is in the owner-only /catalog namespace, which redirects anonymous visitors to /login.",
        );
    }
});

it('pins the landing manifest product URL to the public scalpels.app product page', function (): void {
    $manifest = LandingManifest::fromConfiguration();

    expect($manifest->productUrl)->toBe("https://scalpels.app/products/{$manifest->slug}");
});
