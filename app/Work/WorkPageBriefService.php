<?php

namespace App\Work;

use App\Enums\PageShareKind;
use App\Http\ApiErrorException;
use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Support\Facades\Storage;

final readonly class WorkPageBriefService
{
    /** @return array{page: Page, version: PageVersion, content: string} */
    public function resolve(string $pageId, string $actor): array
    {
        $page = Page::query()->whereKey($pageId)
            ->where(function ($query) use ($actor): void {
                $query->where('owner_user_id', $actor)
                    ->orWhereHas('shares', function ($query) use ($actor): void {
                        $query->whereNull('revoked_at')
                            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                            ->where('kind', PageShareKind::Internal->value)
                            ->where('internal_recipient_user_id', $actor);
                    });
            })
            ->with('latestVersion')
            ->lockForUpdate()
            ->first();
        $version = $page?->latestVersion;
        if (! $page instanceof Page || ! $version instanceof PageVersion) {
            throw new ApiErrorException(404, 'page_unavailable', 'The brief Page is unavailable.');
        }
        $content = rescue(fn (): string => Storage::disk()->get($version->storage_key), null, false);
        if (! is_string($content)) {
            throw new ApiErrorException(409, 'page_content_unavailable', 'The brief Page content is unavailable.');
        }

        return compact('page', 'version', 'content');
    }
}
