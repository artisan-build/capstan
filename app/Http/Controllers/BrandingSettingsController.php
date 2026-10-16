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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

        $description = $contract->describe();

        return response()->view('branding.show', [
            'state' => $state,
            'editing' => $state['draft'] ?? $state['current'],
            'assets' => $this->brands->assets(),
            'contract' => $description,
            'errorFieldNames' => $this->errorFieldNames($description),
            'previewUrl' => $fingerprint === null ? null : $origin->signedBrandPreviewUrl($fingerprint),
            'draftFingerprint' => $fingerprint,
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
        foreach (['tone_notes', 'header_html', 'footer_html', 'custom_css'] as $field) {
            if (array_key_exists($field, $changes) && $changes[$field] === null) {
                $changes[$field] = '';
            }
        }
        foreach (['logo_light', 'logo_dark', 'mark'] as $slot) {
            if (isset($changes['assets']) && is_array($changes['assets']) && ($changes['assets'][$slot] ?? null) === '') {
                $changes['assets'][$slot] = null;
            }
        }

        try {
            $this->brands->updateDraft($actor, $changes);
        } catch (ValidationException $exception) {
            return to_route('branding.show')->withErrors($exception->errors())->withInput();
        }

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

        $validated = $request->validate([
            'draft_fingerprint' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/D'],
        ]);
        $version = $this->brands->applyDraft($actor, (string) $validated['draft_fingerprint']);

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

    /**
     * @param  array<string, mixed>  $contract
     * @return array<string, string>
     */
    private function errorFieldNames(array $contract): array
    {
        $names = [
            'brand kit' => 'Brand settings',
            'display_name' => 'Display name',
            'colours' => 'Colours',
            'spacing' => 'Spacing scale',
            'radius' => 'Radius scale',
            'fonts' => 'System font stacks',
            'fonts.heading' => 'Heading font',
            'fonts.heading.type' => 'Heading font type',
            'fonts.heading.value' => 'Heading font',
            'fonts.body' => 'Body font',
            'fonts.body.type' => 'Body font type',
            'fonts.body.value' => 'Body font',
            'assets' => 'Selected assets',
            'assets.logo_light' => 'Light logo',
            'assets.logo_dark' => 'Dark logo',
            'assets.mark' => 'Mark',
            'tone_notes' => 'Tone notes',
            'default_layout' => 'Default layout',
            'header_html' => 'Header HTML',
            'footer_html' => 'Footer HTML',
            'custom_css' => 'Custom CSS',
            'draft' => 'Brand draft',
            'draft_fingerprint' => 'Brand draft',
            'kind' => 'Asset kind',
            'asset' => 'Asset',
            'slot' => 'Asset slot',
            'version' => 'Brand version',
        ];

        foreach (['light' => 'Light colours', 'dark' => 'Dark colours'] as $mode => $label) {
            foreach ($contract['colour_tokens'] as $token) {
                $names['colours.'.$mode.'.'.$token] = $label.' — '.Str::headline($token);
            }
        }
        foreach ($contract['spacing_tokens'] as $token) {
            $names['spacing.'.$token] = 'Spacing '.Str::upper($token);
        }
        foreach ($contract['radius_tokens'] as $token) {
            $names['radius.'.$token] = 'Radius '.Str::upper($token);
        }

        return $names;
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
