<?php

namespace App\Support;

final readonly class BrandPageRenderer
{
    public function __construct(private BrandStylesheet $stylesheet) {}

    public function render(string $body, string $contentType): string
    {
        if (! in_array($contentType, ['text/html', 'application/xhtml+xml'], true)) {
            return $body;
        }

        $style = '<style data-capstan-brand="applied">'.$this->stylesheet->current().'</style>';
        $rendered = preg_replace('/(<head\b[^>]*>)/i', '$1'.$style, $body, 1, $count);

        return $count === 1 && is_string($rendered) ? $rendered : $style.$body;
    }
}
