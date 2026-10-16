<?php

namespace App\Support;

final readonly class BrandPageRenderer
{
    public function __construct(
        private BrandStylesheet $stylesheet,
        private BrandKitService $brands,
        private HtmlHeadStyleInjector $injector,
    ) {}

    public function render(string $body, string $contentType): string
    {
        return $this->renderResponse($body, $contentType)['body'];
    }

    /**
     * @return array{body: string, allow_same_origin_images: bool, allow_same_origin_fonts: bool}
     */
    public function renderResponse(string $body, string $contentType): array
    {
        if (! in_array($contentType, ['text/html', 'application/xhtml+xml'], true)) {
            return [
                'body' => $body,
                'allow_same_origin_images' => false,
                'allow_same_origin_fonts' => false,
            ];
        }

        $brand = $this->brands->current();
        $style = '<style data-capstan-brand="applied">'.$this->stylesheet->serialize($brand).'</style>';

        return [
            'body' => $this->injector->inject($body, $style),
            'allow_same_origin_images' => is_string($brand['assets']['logo_light'])
                || is_string($brand['assets']['logo_dark'])
                || is_string($brand['assets']['mark']),
            'allow_same_origin_fonts' => $brand['fonts']['heading']['type'] === 'asset'
                || $brand['fonts']['body']['type'] === 'asset',
        ];
    }
}
