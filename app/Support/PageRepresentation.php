<?php

namespace App\Support;

use App\Enums\PageLayout;
use App\Mcp\ToolResponse;
use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;

final readonly class PageRepresentation
{
    public function __construct(private ArtifactRenderOrigin $origin) {}

    /** @return array<string, mixed> */
    public function summary(Page $page): array
    {
        $page->loadMissing('latestVersion');

        return [
            'id' => $page->id,
            'url' => $this->origin->appOrigin().route('pages.show', ['page' => $page], false),
            'title' => $page->title,
            'description' => $page->description,
            'recipe' => $page->refresh_recipe,
            'layout' => $page->layout->value,
            'latest_version' => $page->latestVersion instanceof PageVersion
                ? $this->version($page, $page->latestVersion)
                : null,
            'created_at' => $page->created_at->toJSON(),
            'updated_at' => $page->updated_at->toJSON(),
        ];
    }

    /** @return array<string, mixed> */
    public function listItem(Page $page): array
    {
        return [
            'id' => $page->id,
            'url' => $this->pageUrl($page->id),
            'title' => $page->title,
            'layout' => $page->layout->value,
            'version_count' => $page->version_count,
            'created_at' => $page->created_at->toJSON(),
            'updated_at' => $page->updated_at->toJSON(),
        ];
    }

    /**
     * @param  Collection<int, PageVersion>  $versions
     * @return array<string, mixed>
     */
    public function detail(Page $page, Collection $versions, ?string $nextCursor, bool $hasMore, int $limit): array
    {
        $page->loadMissing('latestVersion');
        $latest = $page->latestVersion;

        return [
            ...$this->summary($page),
            'versions' => $versions
                ->map(fn (PageVersion $version): array => $this->version($page, $version))
                ->values()
                ->all(),
            'version_count' => $page->version_count,
            'next_version_cursor' => $nextCursor,
            'has_more_versions' => $hasMore,
            'version_limit' => $limit,
            'content' => $latest instanceof PageVersion ? $this->content($latest) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function version(Page $page, PageVersion $version): array
    {
        return [
            'id' => $version->id,
            'number' => $version->version_number,
            'url' => $this->versionUrl($page->id, $version->id),
            'content_type' => $version->content_type,
            'size_bytes' => $version->size_bytes,
            'publisher_user_id' => $version->publisher_user_id,
            'publisher_source' => $version->publisher_source,
            'publisher_tool' => $version->publisher_tool,
            'layout' => $version->layout->value,
            'created_at' => $version->created_at->toJSON(),
        ];
    }

    public function proposedDetailFits(
        string $ownerUserId,
        string $title,
        ?string $description,
        string $recipe,
        PageLayout $layout,
        string $content,
        string $contentType,
        string $publisherTool,
    ): bool {
        $id = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
        $timestamp = '2026-10-15T23:59:59.999999Z';
        $version = [
            'id' => $id,
            'number' => PHP_INT_MAX,
            'url' => $this->versionUrl($id, $id),
            'content_type' => $contentType,
            'size_bytes' => strlen($content),
            'publisher_user_id' => $ownerUserId,
            'publisher_source' => 'mcp',
            'publisher_tool' => $publisherTool,
            'layout' => $layout->value,
            'created_at' => $timestamp,
        ];

        return ToolResponse::fits(['page' => [
            'id' => $id,
            'url' => $this->pageUrl($id),
            'title' => $title,
            'description' => $description,
            'recipe' => $recipe,
            'layout' => $layout->value,
            'latest_version' => $version,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'versions' => [$version],
            'version_count' => PHP_INT_MAX,
            'next_version_cursor' => str_repeat('x', 2048),
            'has_more_versions' => true,
            'version_limit' => GetPageLimit::DEFAULT,
            'content' => $content,
        ]]);
    }

    public function content(PageVersion $version): ?string
    {
        $content = rescue(fn (): string => Storage::disk()->get($version->storage_key), null, false);

        return is_string($content) ? $content : null;
    }

    private function pageUrl(string $pageId): string
    {
        return $this->origin->appOrigin().route('pages.show', ['page' => $pageId], false);
    }

    private function versionUrl(string $pageId, string $versionId): string
    {
        return $this->origin->appOrigin().route('pages.versions.show', [
            'page' => $pageId,
            'version' => $versionId,
        ], false);
    }
}
