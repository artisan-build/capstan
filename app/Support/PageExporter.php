<?php

namespace App\Support;

use App\Enums\PageLayout;
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
        $brand = $this->brands->current();
        $chrome = $version->layout === PageLayout::Plain || ! in_array($version->content_type, ['text/html', 'application/xhtml+xml'], true)
            ? '' : $brand['header_html'].$brand['footer_html'];
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
                [$html, $chrome] = str_replace([$origin.$path, '//'.parse_url($origin, PHP_URL_HOST).$path], $data, [$html, $chrome]);
            }
            [$html, $chrome] = str_replace($path, $data, [$html, $chrome]);
        }
        // Brand-authored links are injected dependencies, not page-authored
        // content. Only embedded data and document-local fragments stand alone.
        $document = new \DOMDocument;
        if (! @$document->loadXML('<div>'.$chrome.'</div>', LIBXML_NONET)) {
            return null;
        }
        foreach ($document->getElementsByTagName('*') as $element) {
            foreach ($element->attributes as $attribute) {
                if (in_array(strtolower($attribute->localName), ['src', 'href'], true)) {
                    $reference = trim($attribute->value);
                    if ($reference !== '' && ! str_starts_with($reference, '#') && stripos($reference, 'data:') !== 0) {
                        return null;
                    }
                }
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
