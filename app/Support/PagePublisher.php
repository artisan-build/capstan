<?php

namespace App\Support;

use App\Enums\PageLayout;
use App\Http\ApiErrorException;
use App\Models\Artifact;
use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Support\Facades\DB;

final readonly class PagePublisher
{
    public function __construct(private BrandKitService $brands) {}

    public function publish(
        string $pageId,
        string $ownerUserId,
        string $title,
        ?string $description,
        string $refreshRecipe,
        ?PageLayout $layout,
        string $content,
        string $contentType,
    ): Page {
        $layout ??= PageLayout::from($this->brands->current()['default_layout']);

        return DB::transaction(function () use ($pageId, $ownerUserId, $title, $description, $refreshRecipe, $layout, $content, $contentType): Page {
            $page = new Page([
                'owner_user_id' => $ownerUserId,
                'title' => $title,
                'description' => $description,
                'refresh_recipe' => $refreshRecipe,
                'layout' => $layout,
                'version_count' => 1,
            ]);
            $page->id = $pageId;
            $page->save();

            $version = $this->storeVersion(
                page: $page,
                versionNumber: 1,
                publisherUserId: $ownerUserId,
                publisherTool: 'publish_page',
                refreshRecipe: $refreshRecipe,
                layout: $layout,
                content: $content,
                contentType: $contentType,
            );
            $page->forceFill(['latest_version_id' => $version->id])->save();

            return $page->refresh()->load('latestVersion');
        }, 3);
    }

    /** @param array{title?: string, description?: string, refresh_recipe?: string, layout?: PageLayout} $replacements */
    public function update(
        string $pageId,
        string $ownerUserId,
        string $versionId,
        array $replacements,
        string $content,
        string $contentType,
    ): Page {
        return DB::transaction(function () use ($pageId, $ownerUserId, $versionId, $replacements, $content, $contentType): Page {
            // The atomic increment is the serialization point on both SQLite and
            // PostgreSQL. Numbering never depends on max(version_number) + 1.
            $updated = Page::query()
                ->whereKey($pageId)
                ->where('owner_user_id', $ownerUserId)
                ->increment('version_count');

            if ($updated !== 1) {
                throw new ApiErrorException(404, 'page_not_found', 'The page is unavailable.');
            }

            $page = Page::query()->whereKey($pageId)->lockForUpdate()->firstOrFail();
            $title = $replacements['title'] ?? $page->title;
            $description = array_key_exists('description', $replacements) ? $replacements['description'] : $page->description;
            $refreshRecipe = $replacements['refresh_recipe'] ?? $page->refresh_recipe;
            $layout = $replacements['layout'] ?? $page->layout;

            $version = $this->storeVersion(
                page: $page,
                versionNumber: $page->version_count,
                publisherUserId: $ownerUserId,
                publisherTool: 'update_page',
                refreshRecipe: $refreshRecipe,
                layout: $layout,
                content: $content,
                contentType: $contentType,
                versionId: $versionId,
            );

            $page->forceFill([
                'title' => $title,
                'description' => $description,
                'refresh_recipe' => $refreshRecipe,
                'layout' => $layout,
                'latest_version_id' => $version->id,
            ])->save();

            return $page->refresh()->load('latestVersion');
        }, 3);
    }

    private function storeVersion(
        Page $page,
        int $versionNumber,
        string $publisherUserId,
        string $publisherTool,
        string $refreshRecipe,
        PageLayout $layout,
        string $content,
        string $contentType,
        ?string $versionId = null,
    ): PageVersion {
        [$contentHash, $storageKey] = Artifact::storeBlob($content);
        $version = new PageVersion([
            'version_number' => $versionNumber,
            'content_type' => $contentType,
            'size_bytes' => strlen($content),
            'content_hash' => $contentHash,
            'storage_key' => $storageKey,
            'publisher_user_id' => $publisherUserId,
            'publisher_source' => 'mcp',
            'publisher_tool' => $publisherTool,
            'refresh_recipe' => $refreshRecipe,
            'layout' => $layout,
        ]);

        if ($versionId !== null) {
            $version->id = $versionId;
        }

        $page->versions()->save($version);

        return $version;
    }
}
