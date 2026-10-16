<?php

namespace App\Support;

use App\Enums\PageLayout;

final readonly class BrandPageRenderer
{
    public function __construct(
        private BrandStylesheet $stylesheet,
        private BrandKitService $brands,
        private HtmlHeadStyleInjector $injector,
        private PageComponentStylesheet $components,
    ) {}

    public function render(string $body, string $contentType): string
    {
        return $this->renderResponse($body, $contentType)['body'];
    }

    /**
     * @return array{body: string, allow_same_origin_images: bool}
     */
    public function renderResponse(string $body, string $contentType, ?PageLayout $layout = PageLayout::Plain): array
    {
        if (! in_array($contentType, ['text/html', 'application/xhtml+xml'], true)) {
            return [
                'body' => $body,
                'allow_same_origin_images' => false,
            ];
        }

        $brand = $this->brands->current();
        $layout ??= PageLayout::from($brand['default_layout']);
        $css = $this->stylesheet->serialize($brand).$this->components->serialize().$brand['custom_css'];
        if ($contentType === 'application/xhtml+xml') {
            $css = htmlspecialchars($css, ENT_XML1 | ENT_NOQUOTES, 'UTF-8');
        }
        $style = '<style data-capstan-brand="applied">'.$css.'</style>';
        if ($layout !== PageLayout::Plain) {
            $before = '<div data-testid="page-layout-'.$layout->value.'" class="capstan-page capstan-layout-'.$layout->value.'">';
            if ($brand['header_html'] !== '') {
                $before .= '<header data-testid="page-header" class="capstan-header">'.$brand['header_html'].'</header>';
            }
            $before .= '<main data-testid="page-content" class="capstan-content">';
            $after = '</main>';
            if ($brand['footer_html'] !== '') {
                $after .= '<footer data-testid="page-footer" class="capstan-footer">'.$brand['footer_html'].'</footer>';
            }
            $after .= '</div>';
            $body = $this->injector->wrapBody($body, $before, $after, $contentType);
        }

        return [
            'body' => $this->injector->inject($body, $style, $contentType),
            'allow_same_origin_images' => is_string($brand['assets']['logo_light'])
                || is_string($brand['assets']['logo_dark'])
                || is_string($brand['assets']['mark']),
        ];
    }
}
