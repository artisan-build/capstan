<?php

namespace App\Http\Controllers;

use App\Features\Artifacts;
use App\Models\Page;
use App\Models\PageVersion;
use App\Support\ArtifactRenderOrigin;
use App\Support\HumanPageAccess;
use App\Support\PageExporter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

final class PageExportController extends Controller
{
    public function __construct(private readonly HumanPageAccess $access) {}

    public function __invoke(
        Request $request,
        string $page,
        ArtifactRenderOrigin $origin,
        PageExporter $exporter,
    ): Response {
        if (! Feature::active(Artifacts::class)
            || ! $origin->isConfigured()
            || $request->getHost() === $origin->renderHost()) {
            return $this->notFound();
        }

        $owner = $this->access->admittedUser($request);

        if ($owner === null || ! Str::isUuid($page)) {
            return $this->notFound();
        }

        $ownedPage = Page::query()
            ->with('latestVersion')
            ->whereKey($page)
            ->where('owner_user_id', (string) $owner->getKey())
            ->first();
        $version = $ownedPage?->latestVersion;

        if (! $ownedPage instanceof Page || ! $version instanceof PageVersion) {
            return $this->notFound();
        }

        $html = $exporter->export($version);

        if ($html === null) {
            return $this->notFound();
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="page-'.$ownedPage->id.'.html"',
            'Cache-Control' => 'private, no-store, no-transform',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function notFound(): Response
    {
        return response('', 404);
    }
}
