<?php

namespace App\Support;

use App\Enums\BrandAssetKind;
use App\Models\BrandAsset;
use App\Models\BrandKit;
use App\Models\BrandVersion;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\RolePolicy;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class BrandKitService
{
    private const string INSTALLATION_KEY = 'installation';

    public function __construct(
        private BrandKitSchema $schema,
        private BrandAssetInspector $assets,
    ) {}

    /** @return array{defaults: array<string, mixed>, current: array<string, mixed>, draft: array<string, mixed>|null, current_version: int|null, versions: list<array<string, mixed>>} */
    public function read(): array
    {
        $kit = BrandKit::query()->find(self::INSTALLATION_KEY);

        return [
            ...$this->state($kit),
            'versions' => $this->versionHistory($kit?->current_version_id),
        ];
    }

    /** @return array{defaults: array<string, mixed>, current: array<string, mixed>, draft: array<string, mixed>|null, current_version: int|null} */
    public function readCurrentState(): array
    {
        return $this->state(BrandKit::query()->find(self::INSTALLATION_KEY));
    }

    /** @return array<string, mixed> */
    public function current(): array
    {
        return $this->readCurrentState()['current'];
    }

    /** @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function updateDraft(User $actor, array $changes): array
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($changes): array {
            $kit = $this->lockedKit();
            $base = $kit->draft ?? $this->currentConfiguration($kit) ?? $this->schema->defaults();
            $draft = $this->schema->mergeAndNormalize($base, $changes);
            $this->assertAssetSelections($draft);
            $kit->forceFill(['draft' => $draft])->save();

            $normalized = (array_key_exists('header_html', $changes) && $changes['header_html'] !== $draft['header_html'])
                || (array_key_exists('footer_html', $changes) && $changes['footer_html'] !== $draft['footer_html']);

            return [...$draft, 'normalized' => $normalized];
        });
    }

    /** @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function validateDraftChanges(array $changes): array
    {
        $kit = BrandKit::query()->find(self::INSTALLATION_KEY);
        $storedDraft = $kit instanceof BrandKit ? $kit->draft : null;
        $base = $storedDraft ?? ($kit instanceof BrandKit ? $this->currentConfiguration($kit) : null) ?? $this->schema->defaults();
        $draft = $this->schema->mergeAndNormalize($base, $changes);
        $this->assertAssetSelections($draft);

        return $draft;
    }

    public function uploadAsset(User $actor, BrandAssetKind|string $kind, string $bytes, ?string $assetId = null): BrandAsset
    {
        $this->authorize($actor);
        $resolvedKind = $kind instanceof BrandAssetKind ? $kind : BrandAssetKind::tryFrom($kind);
        if (! $resolvedKind instanceof BrandAssetKind) {
            throw ValidationException::withMessages(['kind' => 'The brand asset kind is not supported.']);
        }
        $metadata = $this->assets->inspect($bytes);
        $id = $assetId ?? (string) Str::uuid();
        if (! Str::isUuid($id)) {
            throw ValidationException::withMessages(['asset' => 'The brand asset identity is invalid.']);
        }
        $storageKey = 'brand-assets/'.$metadata['content_hash'].'/'.$id;
        $disk = Storage::disk();

        if (! $disk->put($storageKey, $bytes, ['visibility' => 'private'])) {
            throw ValidationException::withMessages(['asset' => 'The brand asset could not be stored.']);
        }

        try {
            $asset = new BrandAsset;
            $asset->forceFill([
                'id' => $id,
                'kind' => $resolvedKind,
                'content_type' => $metadata['content_type'],
                'size_bytes' => $metadata['size_bytes'],
                'content_hash' => $metadata['content_hash'],
                'storage_key' => $storageKey,
                'created_by_user_id' => (string) $actor->getKey(),
            ])->save();

            return $asset;
        } catch (Throwable $exception) {
            $disk->delete($storageKey);
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function selectAsset(User $actor, string $slot, ?string $assetId): array
    {
        $this->authorize($actor);
        $asset = $assetId === null ? null : BrandAsset::query()->find($assetId);

        if ($assetId !== null && ! $asset instanceof BrandAsset) {
            throw ValidationException::withMessages(['asset' => 'The selected brand asset does not exist.']);
        }

        return match ($slot) {
            'logo_light', 'logo_dark' => $this->selectImage($actor, $slot, $asset, BrandAssetKind::Logo),
            'mark' => $this->selectImage($actor, $slot, $asset, BrandAssetKind::Mark),
            default => throw ValidationException::withMessages(['slot' => 'The brand asset slot is not supported.']),
        };
    }

    public function applyDraft(User $actor, ?string $expectedFingerprint = null): BrandVersion
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $expectedFingerprint): BrandVersion {
            $kit = $this->lockedKit();
            if ($kit->draft === null) {
                throw ValidationException::withMessages(['draft' => 'There is no brand draft to apply.']);
            }
            $configuration = $this->schema->normalize($kit->draft);
            if ($expectedFingerprint !== null
                && ! hash_equals($this->draftFingerprint($configuration), $expectedFingerprint)) {
                throw ValidationException::withMessages([
                    'draft_fingerprint' => 'The brand draft changed. Review the current preview before applying.',
                ]);
            }
            $this->assertAssetSelections($configuration);
            $next = (int) $kit->versions()->max('version_number') + 1;
            $version = $kit->versions()->create([
                'version_number' => $next,
                'configuration' => $configuration,
                'applied_by_user_id' => (string) $actor->getKey(),
            ]);
            $kit->forceFill([
                'current_version_id' => $version->id,
                'current_version_number' => $next,
                'draft' => null,
            ])->save();

            return $version;
        });
    }

    public function revertToVersion(User $actor, int $versionNumber): BrandVersion
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $versionNumber): BrandVersion {
            $kit = $this->lockedKit();
            $source = $kit->versions()->where('version_number', $versionNumber)->first();
            if (! $source instanceof BrandVersion) {
                throw ValidationException::withMessages(['version' => 'The selected brand version does not exist.']);
            }

            $configuration = $this->schema->normalize($source->configuration);
            $this->assertAssetSelections($configuration);
            $next = (int) $kit->versions()->max('version_number') + 1;
            $version = $kit->versions()->create([
                'version_number' => $next,
                'configuration' => $configuration,
                'applied_by_user_id' => (string) $actor->getKey(),
            ]);
            $kit->forceFill([
                'current_version_id' => $version->id,
                'current_version_number' => $next,
                'draft' => null,
            ])->save();

            return $version;
        });
    }

    /** @return array<string, mixed>|null */
    public function previewConfiguration(?string $fingerprint = null): ?array
    {
        $draft = BrandKit::query()->find(self::INSTALLATION_KEY)?->draft;
        if (! is_array($draft)) {
            return null;
        }

        $draft = $this->schema->normalize($draft);
        $this->assertAssetSelections($draft);
        if ($fingerprint !== null && ! hash_equals($this->draftFingerprint($draft), $fingerprint)) {
            return null;
        }

        return $draft;
    }

    /** @param array<string, mixed>|null $draft */
    public function draftFingerprint(?array $draft = null): ?string
    {
        $draft ??= BrandKit::query()->find(self::INSTALLATION_KEY)?->draft;

        return is_array($draft) ? hash('sha256', JsonCanonicalizer::encode($draft)) : null;
    }

    /**
     * @return array{versions: list<array<string, mixed>>, has_more: bool, last_version_number: int|null}
     */
    public function versionHistoryPage(int $limit, ?int $beforeVersionNumber = null): array
    {
        $kit = BrandKit::query()->find(self::INSTALLATION_KEY);
        $candidates = BrandVersion::query()
            ->where('brand_kit_key', self::INSTALLATION_KEY)
            ->when($beforeVersionNumber !== null, fn ($query) => $query->where('version_number', '<', $beforeVersionNumber))
            ->orderByDesc('version_number')
            ->limit($limit + 1)
            ->get();
        $versions = $candidates->take($limit);
        $last = $versions->last();
        $metadata = [];
        foreach ($versions as $version) {
            $metadata[] = $this->versionMetadata($version, $kit?->current_version_id);
        }

        return [
            'versions' => $metadata,
            'has_more' => $candidates->count() > $limit,
            'last_version_number' => $last instanceof BrandVersion ? $last->version_number : null,
        ];
    }

    /** @return list<BrandAsset> */
    public function assets(): array
    {
        return array_values(BrandAsset::query()->latest('created_at')->orderByDesc('id')->get()->all());
    }

    public function assertCanManage(User $actor): void
    {
        $this->authorize($actor);
    }

    public function assertCanRead(User $actor): void
    {
        if ($actor->status !== 'active'
            || OffboardedSubject::userIsOffboarded((string) $actor->getAuthIdentifier())) {
            throw new AuthorizationException('Only an admitted user may read branding.');
        }
    }

    /** @return list<string> */
    public function currentAssetIds(): array
    {
        $current = $this->current();
        $ids = array_values(array_filter([
            $current['assets']['logo_light'],
            $current['assets']['logo_dark'],
            $current['assets']['mark'],
        ], is_string(...)));

        return array_values(array_unique($ids));
    }

    private function lockedKit(): BrandKit
    {
        BrandKit::query()->firstOrCreate(
            ['installation_key' => self::INSTALLATION_KEY],
            ['current_version_number' => 0],
        );

        return BrandKit::query()->whereKey(self::INSTALLATION_KEY)->lockForUpdate()->firstOrFail();
    }

    /** @return array<string, mixed>|null */
    private function currentConfiguration(BrandKit $kit): ?array
    {
        if ($kit->current_version_id === null) {
            return null;
        }

        return BrandVersion::query()->find($kit->current_version_id)?->configuration;
    }

    /** @return array{defaults: array<string, mixed>, current: array<string, mixed>, draft: array<string, mixed>|null, current_version: int|null} */
    private function state(?BrandKit $kit): array
    {
        $defaults = $this->schema->defaults();
        $version = $kit instanceof BrandKit && $kit->current_version_id !== null
            ? BrandVersion::query()->find($kit->current_version_id)
            : null;

        return [
            'defaults' => $defaults,
            'current' => $version instanceof BrandVersion ? $this->schema->normalize($version->configuration) : $defaults,
            'draft' => $kit?->draft,
            'current_version' => $version instanceof BrandVersion ? $version->version_number : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function versionHistory(?string $currentVersionId): array
    {
        return array_values(BrandVersion::query()
            ->where('brand_kit_key', self::INSTALLATION_KEY)
            ->orderByDesc('version_number')
            ->get()
            ->map(fn (BrandVersion $version): array => $this->versionMetadata($version, $currentVersionId))
            ->values()
            ->all());
    }

    /** @return array<string, mixed> */
    private function versionMetadata(BrandVersion $version, ?string $currentVersionId): array
    {
        return [
            'id' => $version->id,
            'version_number' => $version->version_number,
            'applied_by_user_id' => $version->applied_by_user_id,
            'applied_at' => $version->created_at?->toIso8601String(),
            'is_current' => $version->id === $currentVersionId,
        ];
    }

    /** @param array<string, mixed> $configuration */
    private function assertAssetSelections(array $configuration): void
    {
        $expected = [];
        foreach (['logo_light', 'logo_dark'] as $slot) {
            if (is_string($configuration['assets'][$slot])) {
                $expected[$configuration['assets'][$slot]] = BrandAssetKind::Logo;
            }
        }
        if (is_string($configuration['assets']['mark'])) {
            $expected[$configuration['assets']['mark']] = BrandAssetKind::Mark;
        }

        foreach ($expected as $assetId => $kind) {
            $asset = BrandAsset::query()->find($assetId);
            if (! $asset instanceof BrandAsset || $asset->kind !== $kind) {
                throw ValidationException::withMessages(['assets' => 'A selected asset is missing or has the wrong kind.']);
            }
        }
    }

    /** @return array<string, mixed> */
    private function selectImage(User $actor, string $slot, ?BrandAsset $asset, BrandAssetKind $kind): array
    {
        if ($asset !== null && $asset->kind !== $kind) {
            throw ValidationException::withMessages(['asset' => 'The selected asset has the wrong kind for this slot.']);
        }

        return $this->updateDraft($actor, ['assets' => [$slot => $asset?->id]]);
    }

    private function authorize(User $actor): void
    {
        if ($actor->status !== 'active'
            || ! RolePolicy::canManageMembers($actor->role)
            || OffboardedSubject::userIsOffboarded((string) $actor->getAuthIdentifier())) {
            throw new AuthorizationException('Only an admitted Owner or Admin may change branding.');
        }
    }
}
