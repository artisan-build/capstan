<?php

namespace App\Http\Controllers;

use App\Enums\BrandAssetKind;
use App\Features\Artifacts;
use App\Support\ArtifactRenderOrigin;
use App\Support\BrandContract;
use App\Support\BrandKitService;
use App\Support\HumanPageAccess;
use ArtisanBuild\BuiltForCloud\RolePolicy;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Laravel\Pennant\Feature;

final readonly class BrandingSettingsController
{
    public function __construct(
        private HumanPageAccess $access,
        private BrandKitService $brands,
    ) {}

    public function show(Request $request, ArtifactRenderOrigin $origin, BrandContract $contract): Response
    {
        $actor = $this->actor($request, $origin);
        if (! $actor instanceof User) {
            return response('', 404);
        }

        $state = $this->brands->read();
        $fingerprint = $this->brands->draftFingerprint($state['draft']);

        return response()->view('branding.show', [
            'state' => $state,
            'editing' => $state['draft'] ?? $state['current'],
            'assets' => $this->brands->assets(),
            'contract' => $contract->describe(),
            'previewUrl' => $fingerprint === null ? null : $origin->signedBrandPreviewUrl($fingerprint),
        ]);
    }

    public function update(Request $request, ArtifactRenderOrigin $origin): RedirectResponse|Response
    {
        $actor = $this->actor($request, $origin);
        if (! $actor instanceof User) {
            return response('', 404);
        }

        $changes = Arr::only($request->all(), [
            'display_name', 'colours', 'spacing', 'radius', 'fonts', 'assets', 'tone_notes',
            'default_layout', 'header_html', 'footer_html', 'custom_css',
        ]);
        foreach (['logo_light', 'logo_dark', 'mark'] as $slot) {
            if (isset($changes['assets']) && is_array($changes['assets']) && ($changes['assets'][$slot] ?? null) === '') {
                $changes['assets'][$slot] = null;
            }
        }

        $this->brands->updateDraft($actor, $changes);

        return to_route('branding.show')->with('status', 'Brand draft saved.');
    }

    public function upload(Request $request, ArtifactRenderOrigin $origin): RedirectResponse|Response
    {
        $actor = $this->actor($request, $origin);
        if (! $actor instanceof User) {
            return response('', 404);
        }

        $validated = $request->validate([
            'slot' => ['required', 'string', 'in:logo_light,logo_dark,mark'],
            'asset' => ['required', 'file', 'max:'.(int) ceil(((int) config('capstan.brand.max_image_bytes')) / 1024)],
        ]);
        $slot = (string) $validated['slot'];
        $kind = $slot === 'mark' ? BrandAssetKind::Mark : BrandAssetKind::Logo;
        $file = $request->file('asset');
        $bytes = $file instanceof UploadedFile ? $file->get() : null;
        if (! is_string($bytes)) {
            return back()->withErrors(['asset' => 'The uploaded asset could not be read.'])->withInput();
        }

        $asset = $this->brands->uploadAsset($actor, $kind, $bytes);
        $this->brands->selectAsset($actor, $slot, $asset->id);

        return to_route('branding.show')->with('status', 'Brand asset uploaded and selected.');
    }

    public function apply(Request $request, ArtifactRenderOrigin $origin): RedirectResponse|Response
    {
        $actor = $this->actor($request, $origin);
        if (! $actor instanceof User) {
            return response('', 404);
        }

        $version = $this->brands->applyDraft($actor);

        return to_route('branding.show')->with('status', 'Brand version '.$version->version_number.' applied.');
    }

    public function revert(Request $request, int $version, ArtifactRenderOrigin $origin): RedirectResponse|Response
    {
        $actor = $this->actor($request, $origin);
        if (! $actor instanceof User) {
            return response('', 404);
        }

        $applied = $this->brands->revertToVersion($actor, $version);

        return to_route('branding.show')->with('status', 'Brand version '.$applied->version_number.' created from version '.$version.'.');
    }

    private function actor(Request $request, ArtifactRenderOrigin $origin): ?User
    {
        if (! Feature::active(Artifacts::class)
            || ! $origin->isConfigured()
            || $request->getHost() === $origin->renderHost()) {
            return null;
        }

        $actor = $this->access->admittedUser($request);
        if (! $actor instanceof User || ! RolePolicy::canManageMembers($actor->role)) {
            return null;
        }

        $this->brands->assertCanManage($actor);

        return $actor;
    }
}
