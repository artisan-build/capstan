<?php

namespace App\Support;

use App\Models\Page;
use App\Models\PageVersion;
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
    public function detail(Page $page): array
    {
        $page->loadMissing(['latestVersion', 'versions']);
        $latest = $page->latestVersion;

        return [
            ...$this->summary($page),
            'versions' => $page->versions
                ->map(fn (PageVersion $version): array => $this->version($page, $version))
                ->values()
                ->all(),
            'content' => $latest instanceof PageVersion ? $this->content($latest) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function version(Page $page, PageVersion $version): array
    {
        return [
            'id' => $version->id,
            'number' => $version->version_number,
            'url' => $this->origin->appOrigin().route('pages.versions.show', ['page' => $page, 'version' => $version], false),
            'content_type' => $version->content_type,
            'size_bytes' => $version->size_bytes,
            'publisher_user_id' => $version->publisher_user_id,
            'publisher_source' => $version->publisher_source,
            'publisher_tool' => $version->publisher_tool,
            'recipe' => $version->refresh_recipe,
            'layout' => $version->layout->value,
            'created_at' => $version->created_at->toJSON(),
        ];
    }

    public function content(PageVersion $version): ?string
    {
        $content = rescue(fn (): string => Storage::disk()->get($version->storage_key), null, false);

        return is_string($content) ? $content : null;
    }
}
