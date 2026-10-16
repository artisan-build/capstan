<?php

namespace App\Support;

use App\Enums\PageLayout;

final readonly class BrandPreviewDocument
{
    public function __construct(
        private BrandStylesheet $stylesheet,
        private PageComponentStylesheet $components,
        private ArtifactRenderOrigin $origin,
    ) {}

    /** @param array<string, mixed> $brand */
    public function render(array $brand, string $fingerprint): string
    {
        $css = $this->replaceAssetUrls(
            $this->stylesheet->serialize($brand).$this->components->serialize().$brand['custom_css'],
            $brand,
            $fingerprint,
            false,
        );
        $header = $this->replaceAssetUrls($brand['header_html'], $brand, $fingerprint, true);
        $footer = $this->replaceAssetUrls($brand['footer_html'], $brand, $fingerprint, true);
        $title = htmlspecialchars($brand['display_name'].' brand preview', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $sections = '';

        foreach (PageLayout::cases() as $layout) {
            $body = $this->sample($layout);
            if ($layout === PageLayout::Plain) {
                $sections .= '<section data-testid="brand-preview-layout-plain" class="capstan-preview-layout"><h2>Plain</h2>'.$body.'</section>';

                continue;
            }

            $sections .= '<section data-testid="brand-preview-layout-'.$layout->value.'" class="capstan-preview-layout">'
                .'<h2>'.ucfirst($layout->value).'</h2>'
                .'<div class="capstan-page capstan-layout-'.$layout->value.'">'
                .($header === '' ? '' : '<header class="capstan-header">'.$header.'</header>')
                .'<main class="capstan-content">'.$body.'</main>'
                .($footer === '' ? '' : '<footer class="capstan-footer">'.$footer.'</footer>')
                .'</div></section>';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow,noarchive">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$title.'</title>'
            .'<style data-capstan-brand="draft">'.$css.'body{font-family:var(--capstan-font-body)}.capstan-preview-layout{margin:2rem 0}.capstan-preview-layout>h2{font-family:var(--capstan-font-heading);padding:0 1rem}.capstan-preview-layout .capstan-card>h1{font-family:var(--capstan-font-heading)}</style>'
            .'</head><body data-testid="brand-preview">'.$sections.'</body></html>';
    }

    private function sample(PageLayout $layout): string
    {
        $name = ucfirst($layout->value);

        return '<article class="capstan-card"><h1>'.$name.' sample</h1><p>Representative branded content for layout review.</p></article>'
            .'<div class="capstan-grid"><section class="capstan-stat"><div class="capstan-stat-value">42</div><div class="capstan-stat-label">Active items</div></section>'
            .'<section class="capstan-callout capstan-callout-success">A concise success callout.</section></div>'
            .'<p><span class="capstan-badge capstan-badge-warning">Needs review</span></p>'
            .'<table class="capstan-table"><thead><tr><th>Metric</th><th>Value</th></tr></thead><tbody><tr><td>Completion</td><td>84%</td></tr></tbody></table>';
    }

    /** @param array<string, mixed> $brand */
    private function replaceAssetUrls(string $value, array $brand, string $fingerprint, bool $html): string
    {
        foreach (array_values(array_filter($brand['assets'], is_string(...))) as $assetId) {
            $url = $this->origin->signedBrandPreviewAssetUrl($fingerprint, $assetId);
            if ($html) {
                $url = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            $value = str_replace('/brand-assets/'.$assetId, $url, $value);
        }

        return $value;
    }
}
