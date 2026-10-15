<?php

namespace App\Http\Controllers;

use App\Features\Artifacts;
use App\Models\Page;
use App\Models\PageVersion;
use App\Support\ArtifactRenderOrigin;
use ArtisanBuild\BuiltForCloud\Http\Middleware\EnsureUserIsAuthenticated;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PageController extends Controller
{
    public function __construct(private readonly EnsureUserIsAuthenticated $admission) {}

    public function show(Request $request, string $page, ArtifactRenderOrigin $origin): Response
    {
        return $this->viewer($request, $page, null, $origin);
    }

    public function version(Request $request, string $page, string $version, ArtifactRenderOrigin $origin): Response
    {
        return $this->viewer($request, $page, $version, $origin);
    }

    public function content(Request $request, Page $page, PageVersion $version, ArtifactRenderOrigin $origin): StreamedResponse
    {
        abort_unless(Feature::active(Artifacts::class), 404);
        abort_unless($origin->isConfigured(), 404);
        abort_unless($request->getHost() === $origin->renderHost(), 404);
        abort_unless($version->page_id === $page->id, 404);
        abort_unless($request->hasValidSignature(false), 404);

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
        string $pageId,
        ?string $versionId,
        ArtifactRenderOrigin $origin,
    ): Response {
        if (! Feature::active(Artifacts::class)
            || ! $origin->isConfigured()
            || $request->getHost() === $origin->renderHost()) {
            return $this->pageNotFound();
        }

        if (! $this->admitted($request)) {
            return $this->pageNotFound();
        }

        $user = $request->user();

        if (! $user instanceof User
            || ! Str::isUuid($pageId)
            || ($versionId !== null && ! Str::isUuid($versionId))) {
            return $this->pageNotFound();
        }

        $page = Page::query()
            ->whereKey($pageId)
            ->where('owner_user_id', (string) $user->getKey())
            ->first();

        if (! $page instanceof Page) {
            return $this->pageNotFound();
        }

        $version = $versionId === null
            ? $page->latestVersion
            : PageVersion::query()->whereKey($versionId)->where('page_id', $page->id)->first();

        if (! $version instanceof PageVersion) {
            return $this->pageNotFound();
        }

        $page->loadMissing('versions');

        return response()
            ->view('pages.show', [
                'page' => $page,
                'selectedVersion' => $version,
                'contentUrl' => $origin->signedPageContentUrl($page, $version),
            ])
            ->header('X-Frame-Options', 'DENY');
    }

    private function pageNotFound(): Response
    {
        return response('', 404);
    }

    private function admitted(Request $request): bool
    {
        $marker = response('', 204);

        try {
            $result = $this->admission->handle(
                $request,
                static fn (Request $request): Response => $marker,
            );
        } catch (HttpExceptionInterface) {
            return false;
        }

        return $result === $marker;
    }
}
