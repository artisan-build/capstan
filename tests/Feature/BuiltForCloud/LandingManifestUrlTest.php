<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\LandingManifest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

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

it('serves every landing manifest URL publicly, with no redirect', function (): void {
    foreach (capstanManifestUrls() as $field => $url) {
        try {
            $response = Http::withoutRedirecting()->timeout(10)->head($url);
        } catch (ConnectionException $exception) {
            $this->markTestSkipped("Could not reach [{$url}]: {$exception->getMessage()}");
        }

        if ($response->serverError()) {
            $this->markTestSkipped("The host serving [{$url}] answered HTTP {$response->status()}.");
        }

        expect($response->status())->toBe(
            200,
            "The landing manifest [{$field}] URL [{$url}] answered HTTP {$response->status()}".
            ($response->redirect() ? ' to '.$response->header('Location') : '').
            ', but anonymous visitors must get a 200.',
        );
    }
});
