<?php

namespace App\Http\Controllers;

use App\Enums\ArtifactVisibility;
use App\Features\Artifacts as ArtifactsFeature;
use App\Models\Artifact;
use App\Models\User;
use App\Support\ArtifactRenderOrigin;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ArtifactShareController extends Controller
{
    public function show(Request $request, Artifact $artifact, ArtifactRenderOrigin $origin): Response
    {
        abort_unless(Feature::active(ArtifactsFeature::class), 404);
        abort_unless($origin->isConfigured(), 404);
        abort_if($request->getHost() === $origin->renderHostFor($artifact), 404);
        $this->abortIfExpired($artifact);

        $contentUrl = match ($artifact->visibility) {
            ArtifactVisibility::SignedUrl => $this->signedUrlContentUrl($request, $artifact, $origin),
            ArtifactVisibility::OrgAuth => $this->orgAuthContentUrl($request, $artifact, $origin),
        };

        return response()
            ->view('artifacts.show', ['artifact' => $artifact, 'contentUrl' => $contentUrl])
            ->header('X-Frame-Options', 'DENY');
    }

    public function content(Request $request, Artifact $artifact, ArtifactRenderOrigin $origin): StreamedResponse
    {
        abort_unless(Feature::active(ArtifactsFeature::class), 404);
        abort_unless($origin->isConfigured(), 404);
        abort_unless($request->getHost() === $origin->renderHostFor($artifact), 404);
        $this->abortIfExpired($artifact);

        match ($artifact->visibility) {
            ArtifactVisibility::SignedUrl => abort_unless($request->hasValidSignature(false), 403),
            // On the cookieless render origin, a valid signature is the authorization.
            ArtifactVisibility::OrgAuth => $request->hasValidSignature(false) || $this->authorizeOrgArtifact($request, $artifact),
        };

        $disk = Storage::disk();
        $contentLength = $disk->size($artifact->storage_key);
        $stream = $disk->readStream($artifact->storage_key);
        abort_unless(is_resource($stream), 404);

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $artifact->content_type,
            'Content-Length' => (string) $contentLength,
            'Content-Security-Policy' => $origin->contentSecurityPolicy(),
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function signedUrlContentUrl(Request $request, Artifact $artifact, ArtifactRenderOrigin $origin): string
    {
        abort_unless($request->hasValidSignature(), 403);

        return $origin->signedContentUrl($artifact);
    }

    private function orgAuthContentUrl(Request $request, Artifact $artifact, ArtifactRenderOrigin $origin): string
    {
        $this->authorizeOrgArtifact($request, $artifact);

        return $origin->signedContentUrl($artifact);
    }

    private function authorizeOrgArtifact(Request $request, Artifact $artifact): void
    {
        /** @var User|null $user */
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $hasGrant = $artifact->teams()
            ->whereIn('teams.id', $user->teams()->select('teams.id'))
            ->exists();

        abort_unless($hasGrant, 403);
    }

    private function abortIfExpired(Artifact $artifact): void
    {
        abort_if($artifact->expires_at && $artifact->expires_at->isPast(), 404);
    }
}
