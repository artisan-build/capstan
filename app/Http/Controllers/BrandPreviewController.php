<?php

namespace App\Http\Controllers;

use App\Features\Artifacts;
use App\Models\BrandAsset;
use App\Support\ArtifactRenderOrigin;
use App\Support\BrandKitService;
use App\Support\BrandPreviewDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BrandPreviewController extends Controller
{
    public function show(
        Request $request,
        string $fingerprint,
        ArtifactRenderOrigin $origin,
        BrandKitService $brands,
        BrandPreviewDocument $document,
    ): Response {
        if (! $this->available($request, $origin, $fingerprint)) {
            return response('', 404);
        }

        $brand = $brands->previewConfiguration($fingerprint);
        if ($brand === null) {
            return response('', 404);
        }

        $body = $document->render($brand, $fingerprint);
        $hasAssets = array_filter($brand['assets'], is_string(...)) !== [];

        return response($body, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => $origin->contentSecurityPolicy($hasAssets),
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'private, no-store, no-transform',
            'Content-Length' => (string) strlen($body),
        ]);
    }

    public function asset(
        Request $request,
        string $fingerprint,
        string $asset,
        ArtifactRenderOrigin $origin,
        BrandKitService $brands,
    ): StreamedResponse|Response {
        if (! $this->available($request, $origin, $fingerprint) || ! Str::isUuid($asset)) {
            return response('', 404);
        }

        $brand = $brands->previewConfiguration($fingerprint);
        if ($brand === null || ! in_array($asset, array_values($brand['assets']), true)) {
            return response('', 404);
        }

        $brandAsset = BrandAsset::query()
            ->whereIn('content_type', ['image/png', 'image/jpeg', 'image/webp'])
            ->find($asset);
        if (! $brandAsset instanceof BrandAsset) {
            return response('', 404);
        }

        $stream = rescue(fn () => Storage::disk()->readStream($brandAsset->storage_key), null, false);
        if (! is_resource($stream)) {
            return response('', 404);
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

    private function available(Request $request, ArtifactRenderOrigin $origin, string $fingerprint): bool
    {
        return Feature::active(Artifacts::class)
            && $origin->isConfigured()
            && $request->getHost() === $origin->renderHost()
            && preg_match('/\A[0-9a-f]{64}\z/D', $fingerprint) === 1
            && $request->hasValidSignature(false);
    }
}
