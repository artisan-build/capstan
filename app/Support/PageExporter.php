<?php

namespace App\Support;

use App\Models\BrandAsset;
use App\Models\PageVersion;
use Illuminate\Support\Facades\Storage;

final readonly class PageExporter
{
    public function __construct(private BrandPageRenderer $renderer, private BrandKitService $brands) {}

    public function export(PageVersion $version): ?string
    {
        $html = rescue(fn (): string => Storage::disk()->get($version->storage_key), null, false);

        if (! is_string($html)) {
            return null;
        }

        $html = $this->renderer->renderResponse($html, $version->content_type, $version->layout)['body'];
        $origins = array_filter([
            rtrim((string) config('app.url'), '/'),
            rtrim((string) config('capstan.artifacts.render_origin'), '/'),
        ]);
        foreach ($this->brands->currentAssetIds() as $id) {
            $asset = BrandAsset::query()->find($id);
            $bytes = $asset instanceof BrandAsset
                ? rescue(fn (): string => Storage::disk()->get($asset->storage_key), null, false)
                : null;
            if (! is_string($bytes)) {
                return null;
            }
            $path = '/brand-assets/'.$id;
            $data = 'data:'.$asset->content_type.';base64,'.base64_encode($bytes);
            foreach ($origins as $origin) {
                $html = str_replace([$origin.$path, '//'.parse_url($origin, PHP_URL_HOST).$path], $data, $html);
            }
            $html = str_replace($path, $data, $html);
        }
        // Unknown owned dependencies cannot make a self-contained download.
        // Refuse rather than emit a file that silently calls back to Capstan.
        foreach (['/brand-assets/', '/build/assets/', ...array_map(fn (string $origin): string => (string) parse_url($origin, PHP_URL_HOST), $origins)] as $reference) {
            if ($reference !== '' && str_contains($html, $reference)) {
                return null;
            }
        }

        return $html;
    }
}
