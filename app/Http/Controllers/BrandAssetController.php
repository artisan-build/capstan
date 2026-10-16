<?php

namespace App\Http\Controllers;

use App\Enums\BrandAssetKind;
use App\Features\Artifacts;
use App\Models\BrandAsset;
use App\Support\ArtifactRenderOrigin;
use App\Support\BrandKitService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BrandAssetController extends Controller
{
    public function show(
        Request $request,
        string $asset,
        ArtifactRenderOrigin $origin,
        BrandKitService $brands,
    ): StreamedResponse|Response {
        if (! Feature::active(Artifacts::class)
            || ! $origin->isConfigured()
            || $request->getHost() !== $origin->renderHost()
            || ! in_array($asset, $brands->currentAssetIds(), true)) {
            return $this->notFound();
        }

        $brandAsset = BrandAsset::query()
            ->whereIn('kind', [BrandAssetKind::Logo->value, BrandAssetKind::Mark->value])
            ->whereIn('content_type', ['image/png', 'image/jpeg', 'image/webp'])
            ->find($asset);
        if (! $brandAsset instanceof BrandAsset) {
            return $this->notFound();
        }
        $stream = rescue(fn () => Storage::disk()->readStream($brandAsset->storage_key), null, false);
        if (! is_resource($stream)) {
            return $this->notFound();
        }

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $brandAsset->content_type,
            'Content-Length' => (string) $brandAsset->size_bytes,
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'private, no-store, no-transform',
        ]);
    }

    private function notFound(): Response
    {
        return response('', 404);
    }
}
