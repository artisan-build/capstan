<?php

namespace App\Http\Controllers;

use App\Features\Artifacts;
use App\Models\Page;
use App\Models\PageVersion;
use App\Support\ArtifactRenderOrigin;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PageController extends Controller
{
    public function show(Request $request, Page $page, ArtifactRenderOrigin $origin): Response
    {
        $version = $page->latestVersion;
        abort_unless($version instanceof PageVersion, 404);

        return $this->viewer($request, $page, $version, $origin);
    }

    public function version(Request $request, Page $page, PageVersion $version, ArtifactRenderOrigin $origin): Response
    {
        abort_unless($version->page_id === $page->id, 404);

        return $this->viewer($request, $page, $version, $origin);
    }

    public function content(Request $request, Page $page, PageVersion $version, ArtifactRenderOrigin $origin): StreamedResponse
    {
        abort_unless(Feature::active(Artifacts::class), 404);
        abort_unless($origin->isConfigured(), 404);
        abort_unless($request->getHost() === $origin->renderHost(), 404);
        abort_unless($version->page_id === $page->id, 404);
        abort_unless($request->hasValidSignature(false), 403);

        $disk = Storage::disk();
        $stream = rescue(fn () => $disk->readStream($version->storage_key), null, false);
        abort_unless(is_resource($stream), 404);
        $contentLength = rescue(fn (): int => $disk->size($version->storage_key), null, false);
        $headers = [
            'Content-Type' => $version->content_type,
            'Content-Security-Policy' => $origin->contentSecurityPolicy(),
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'private, no-store, no-transform',
        ];

        if ($contentLength !== null) {
            $headers['Content-Length'] = (string) $contentLength;
        }

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, $headers);
    }

    private function viewer(
        Request $request,
        Page $page,
        PageVersion $version,
        ArtifactRenderOrigin $origin,
    ): Response {
        abort_unless(Feature::active(Artifacts::class), 404);
        abort_unless($origin->isConfigured(), 404);
        abort_if($request->getHost() === $origin->renderHost(), 404);
        $user = $request->user();
        abort_unless($user instanceof User && hash_equals($page->owner_user_id, (string) $user->getKey()), 404);

        $page->loadMissing('versions');

        return response()
            ->view('pages.show', [
                'page' => $page,
                'selectedVersion' => $version,
                'contentUrl' => $origin->signedPageContentUrl($page, $version),
            ])
            ->header('X-Frame-Options', 'DENY');
    }
}
