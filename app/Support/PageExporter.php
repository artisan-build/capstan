<?php

namespace App\Support;

use App\Enums\PageLayout;
use App\Models\BrandAsset;
use App\Models\PageVersion;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

final readonly class PageExporter
{
    public function __construct(
        private BrandPageRenderer $renderer,
        private BrandKitService $brands,
        private BrandChromeSanitizer $chromeSanitizer,
    ) {}

    public function export(PageVersion $version): ?string
    {
        $html = rescue(fn (): string => Storage::disk()->get($version->storage_key), null, false);

        if (! is_string($html)) {
            return null;
        }

        $html = $this->renderer->renderResponse($html, $version->content_type, $version->layout)['body'];
        $brand = $this->brands->current();
        $chrome = $version->layout === PageLayout::Plain || ! in_array($version->content_type, ['text/html', 'application/xhtml+xml'], true)
            ? [] : [$brand['header_html'], $brand['footer_html']];
        $origins = array_filter([
            rtrim((string) config('app.url'), '/'),
            rtrim((string) config('capstan.artifacts.render_origin'), '/'),
        ]);
        $assetIds = $this->brands->currentAssetIds();
        foreach ($assetIds as $id) {
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
                $chrome = array_map(fn (string $fragment): string => str_replace([$origin.$path, '//'.parse_url($origin, PHP_URL_HOST).$path], $data, $fragment), $chrome);
            }
            $html = str_replace($path, $data, $html);
            $chrome = array_map(fn (string $fragment): string => str_replace($path, $data, $fragment), $chrome);
        }
        // Re-run the write-time sanitizer after private image inlining. Any
        // parse, validation, or canonicalization difference refuses export.
        foreach ($chrome as $fragment) {
            try {
                if ($this->chromeSanitizer->sanitize($fragment, $assetIds) !== $fragment) {
                    return null;
                }
            } catch (InvalidArgumentException) {
                return null;
            }
        }
        // Unknown owned dependencies cannot make a self-contained download.
        // Refuse rather than emit a file that silently calls back to Capstan.
        foreach (['/brand-assets/', '/build/assets/', ...array_map(fn (string $origin): string => (string) parse_url($origin, PHP_URL_HOST), $origins)] as $reference) {
            if ($reference !== '' && stripos($html, $reference) !== false) {
                return null;
            }
        }

        return $html;
    }
}
