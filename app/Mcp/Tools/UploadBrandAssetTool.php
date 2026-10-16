<?php

namespace App\Mcp\Tools;

use App\Enums\BrandAssetKind;
use App\Features\Artifacts;
use App\Mcp\DurableWriteCoordinator;
use App\Mcp\OwnerSubjectResolver;
use App\Mcp\ToolResponse;
use App\Models\BrandAsset;
use App\Support\BrandAssetInspector;
use App\Support\BrandKitService;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Pennant\Feature;

#[Name('upload_brand_asset')]
#[Description('Idempotently stores a bounded base64-encoded PNG, JPEG, or WebP logo or mark after decoding and sniffing it. Fonts are unsupported. Owner or Admin only.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Write)]
final class UploadBrandAssetTool extends BrandTool
{
    use AdvertisesToolClassification;
    use AdvertisesToolEffect;

    public function schema(JsonSchema $schema): array
    {
        $maximumEncodedBytes = (int) ceil(((int) config('capstan.brand.max_mcp_image_bytes', 700 * 1024)) / 3) * 4;

        return [
            'idempotency_key' => $schema->string()->min(1)->max(255)->required(),
            'kind' => $schema->string()->enum(BrandAssetKind::class)->required(),
            'bytes_base64' => $schema->string()->min(16)->max($maximumEncodedBytes)->required(),
        ];
    }

    public function handle(
        Request $request,
        OwnerSubjectResolver $owners,
        DurableWriteCoordinator $writes,
        BrandKitService $brands,
        BrandAssetInspector $inspector,
    ): Response|ResponseFactory {
        $this->requireExactArguments($request, ['idempotency_key', 'kind', 'bytes_base64']);
        if (! Feature::active(Artifacts::class)) {
            return Response::error('Branding is unavailable.');
        }

        [$owner, $actor] = $this->brandActor($owners, $brands, true);
        $maximumBytes = (int) config('capstan.brand.max_mcp_image_bytes', 700 * 1024);
        $maximumEncodedBytes = (int) ceil($maximumBytes / 3) * 4;
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
            'kind' => ['required', 'string', Rule::enum(BrandAssetKind::class)],
            'bytes_base64' => ['required', 'string', 'min:16', 'max:'.$maximumEncodedBytes],
        ]);
        $encoded = (string) $validated['bytes_base64'];
        $bytes = base64_decode($encoded, true);
        if (! is_string($bytes) || strlen($bytes) > $maximumBytes || ! hash_equals(base64_encode($bytes), $encoded)) {
            throw ValidationException::withMessages(['bytes_base64' => 'The asset must use canonical bounded base64 encoding.']);
        }
        $metadata = $inspector->inspect($bytes);
        $kind = BrandAssetKind::from((string) $validated['kind']);
        $createdAssetId = null;
        $createdStorageKey = null;

        try {
            $outcome = $writes->run(
                owner: $owner,
                tool: 'upload_brand_asset',
                idempotencyKey: (string) $validated['idempotency_key'],
                intent: [
                    'kind' => $kind->value,
                    'content_hash' => $metadata['content_hash'],
                    'size_bytes' => $metadata['size_bytes'],
                ],
                targetType: 'brand_asset',
                operation: function (string $targetId) use ($actor, $brands, $bytes, $kind, &$createdAssetId, &$createdStorageKey): array {
                    $asset = $brands->uploadAsset($actor, $kind, $bytes, $targetId);
                    $createdAssetId = $asset->id;
                    $createdStorageKey = $asset->storage_key;

                    return ['asset' => [
                        'id' => $asset->id,
                        'kind' => $asset->kind->value,
                        'content_type' => $asset->content_type,
                        'size_bytes' => $asset->size_bytes,
                        'content_hash' => $asset->content_hash,
                    ]];
                },
            );
        } finally {
            if (is_string($createdAssetId)
                && is_string($createdStorageKey)
                && ! BrandAsset::query()->whereKey($createdAssetId)->exists()) {
                Storage::disk()->delete($createdStorageKey);
            }
        }

        return ToolResponse::structured($outcome);
    }
}
