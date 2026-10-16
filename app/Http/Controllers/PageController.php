<?php

namespace App\Http\Controllers;

use App\Enums\PageShareKind;
use App\Features\Artifacts;
use App\Models\Page;
use App\Models\PageShare;
use App\Models\PageVersion;
use App\Support\ArtifactRenderOrigin;
use App\Support\BrandPageRenderer;
use App\Support\HumanPageAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PageController extends Controller
{
    public function __construct(private readonly HumanPageAccess $access) {}

    public function index(Request $request, ArtifactRenderOrigin $origin): Response
    {
        if (! Feature::active(Artifacts::class)
            || ! $origin->isConfigured()
            || $request->getHost() === $origin->renderHost()) {
            return $this->pageNotFound();
        }

        $user = $this->access->admittedUser($request);

        if ($user === null) {
            return $this->pageNotFound();
        }

        $pages = Page::query()
            ->where('owner_user_id', (string) $user->getKey())
            ->withExists([
                'shares as has_active_shares' => function ($query): void {
                    $query->whereNull('revoked_at')
                        ->where(function ($query): void {
                            $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                        });
                },
            ])
            ->latest('updated_at')
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'my_pages_page')
            ->withQueryString();

        $sharedPages = Page::query()
            ->whereHas('shares', function ($query) use ($user): void {
                $query->whereNull('revoked_at')
                    ->where(function ($query): void {
                        $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    })
                    ->where('kind', PageShareKind::Internal->value)
                    ->where('internal_recipient_user_id', (string) $user->getKey());
            })
            ->latest('updated_at')
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'shared_pages_page')
            ->withQueryString();

        return response()->view('pages.index', [
            'pages' => $pages,
            'sharedPages' => $sharedPages,
        ]);
    }

    public function show(Request $request, string $page, ArtifactRenderOrigin $origin): Response
    {
        return $this->viewer($request, $page, null, $origin);
    }

    public function version(Request $request, string $page, string $version, ArtifactRenderOrigin $origin): Response
    {
        return $this->viewer($request, $page, $version, $origin);
    }

    public function destroy(Request $request, string $page, ArtifactRenderOrigin $origin): RedirectResponse|Response
    {
        if (! Feature::active(Artifacts::class)
            || ! $origin->isConfigured()
            || $request->getHost() === $origin->renderHost()) {
            return $this->pageNotFound();
        }

        $owner = $this->access->admittedUser($request);

        if ($owner === null || ! Str::isUuid($page)) {
            return $this->pageNotFound();
        }

        $ownedPage = Page::query()
            ->whereKey($page)
            ->where('owner_user_id', (string) $owner->getKey())
            ->first();

        if (! $ownedPage instanceof Page) {
            return $this->pageNotFound();
        }

        $ownedPage->delete();

        return to_route('pages.index');
    }

    public function content(
        Request $request,
        Page $page,
        PageVersion $version,
        ArtifactRenderOrigin $origin,
        BrandPageRenderer $branding,
    ): StreamedResponse {
        abort_unless(Feature::active(Artifacts::class), 404);
        abort_unless($origin->isConfigured(), 404);
        abort_unless($request->getHost() === $origin->renderHost(), 404);
        abort_unless($version->page_id === $page->id, 404);
        abort_unless($request->hasValidSignature(false), 404);

        $disk = Storage::disk();
        $body = rescue(fn () => $disk->get($version->storage_key), null, false);
        abort_unless(is_string($body), 404);
        $rendered = $branding->renderResponse($body, $version->content_type, $version->layout);
        $body = $rendered['body'];
        $headers = [
            'Content-Type' => $version->content_type,
            'Content-Security-Policy' => $origin->contentSecurityPolicy(
                $rendered['allow_same_origin_images'],
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

        $user = $this->access->admittedUser($request);

        if ($user === null
            || ! Str::isUuid($pageId)
            || ($versionId !== null && ! Str::isUuid($versionId))) {
            return $this->pageNotFound();
        }

        $page = Page::query()
            ->whereKey($pageId)
            ->where(function ($query) use ($user, $versionId): void {
                $query->where('owner_user_id', (string) $user->getKey());

                if ($versionId === null) {
                    $query->orWhereHas('shares', function ($query) use ($user): void {
                        $query->whereNull('revoked_at')
                            ->where(function ($query): void {
                                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                            })
                            ->where('kind', PageShareKind::Internal->value)
                            ->where('internal_recipient_user_id', (string) $user->getKey());
                    });
                }
            })
            ->first();

        if (! $page instanceof Page) {
            return $this->pageNotFound();
        }

        $isOwner = $page->owner_user_id === (string) $user->getKey();
        $version = $versionId === null
            ? $page->latestVersion
            : PageVersion::query()->whereKey($versionId)->where('page_id', $page->id)->first();

        if (! $version instanceof PageVersion) {
            return $this->pageNotFound();
        }

        $internalShare = null;
        $shares = null;
        $versions = null;

        if ($isOwner) {
            $versions = $page->versions()
                ->reorder()
                ->orderByDesc('version_number')
                ->orderByDesc('id')
                ->paginate(25, ['*'], 'versions_page')
                ->withQueryString();
            $shares = $page->shares()
                ->with('internalRecipient')
                ->paginate(25, ['*'], 'shares_page')
                ->withQueryString();
        } else {
            $internalShare = $page->shares()
                ->active()
                ->where('kind', PageShareKind::Internal->value)
                ->where('internal_recipient_user_id', (string) $user->getKey())
                ->first();

            if (! $internalShare instanceof PageShare) {
                return $this->pageNotFound();
            }
        }

        return response()
            ->view('pages.show', [
                'page' => $page,
                'selectedVersion' => $version,
                'contentUrl' => $internalShare instanceof PageShare
                    ? $origin->signedPageShareContentUrl($internalShare)
                    : $origin->signedPageContentUrl($page, $version),
                'isOwner' => $isOwner,
                'shares' => $shares,
                'versions' => $versions,
                'shareUrls' => $shares !== null
                    ? $shares->getCollection()->mapWithKeys(fn (PageShare $share): array => [
                        $share->id => $share->kind === PageShareKind::Internal
                            ? null
                            : $origin->signedPageShareViewerUrl($share),
                    ])
                    : collect(),
            ])
            ->header('X-Frame-Options', 'DENY');
    }

    private function pageNotFound(): Response
    {
        return response('', 404);
    }
}
