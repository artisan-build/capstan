<?php

namespace App\Http\Controllers;

use App\Enums\PageShareKind;
use App\Features\Artifacts;
use App\Models\PageShare;
use App\Models\PageVersion;
use App\Support\ArtifactRenderOrigin;
use App\Support\BrandPageRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PublicPageShareController extends Controller
{
    public function show(Request $request, string $share, ArtifactRenderOrigin $origin): Response
    {
        if (! Feature::active(Artifacts::class)
            || ! $origin->isConfigured()
            || $request->getHost() !== $origin->appHost()
            || ! $request->hasValidSignature(false)
            || ! Str::isUuid($share)) {
            return $this->notFound();
        }

        $grant = PageShare::query()
            ->with(['page' => fn ($query) => $query->select(['id', 'title', 'description'])])
            ->whereKey($share)
            ->whereIn('kind', [PageShareKind::External->value, PageShareKind::Link->value])
            ->first();

        if (! $grant instanceof PageShare || ! $grant->isActive()) {
            return $this->notFound();
        }

        return response()
            ->view('pages.shared', [
                'page' => $grant->page,
                'contentUrl' => $origin->signedPageShareContentUrl($grant),
            ])
            ->header('X-Frame-Options', 'DENY');
    }

    public function content(
        Request $request,
        string $share,
        ArtifactRenderOrigin $origin,
        BrandPageRenderer $branding,
    ): StreamedResponse|Response {
        if (! Feature::active(Artifacts::class)
            || ! $origin->isConfigured()
            || $request->getHost() !== $origin->renderHost()
            || ! $request->hasValidSignature(false)
            || ! Str::isUuid($share)) {
            return $this->notFound();
        }

        $grant = PageShare::query()->with('page.latestVersion')->whereKey($share)->first();

        if (! $grant instanceof PageShare || ! $grant->isActive()) {
            return $this->notFound();
        }

        $version = $grant->page->latestVersion;

        if (! $version instanceof PageVersion) {
            return $this->notFound();
        }

        $disk = Storage::disk();
        $body = rescue(fn () => $disk->get($version->storage_key), null, false);

        if (! is_string($body)) {
            return $this->notFound();
        }
        $rendered = $branding->renderResponse($body, $version->content_type);
        $body = $rendered['body'];

        $headers = [
            'Content-Type' => $version->content_type,
            'Content-Security-Policy' => $origin->contentSecurityPolicy(
                $rendered['allow_same_origin_images'],
                $rendered['allow_same_origin_fonts'],
            ),
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'private, no-store, no-transform',
            'Content-Length' => (string) strlen($body),
        ];

        return response()->stream(function () use ($body): void {
            echo $body;
        }, 200, $headers);
    }

    private function notFound(): Response
    {
        return response('', 404);
    }
}
