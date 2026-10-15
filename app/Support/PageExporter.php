<?php

namespace App\Support;

use App\Models\PageVersion;
use Illuminate\Support\Facades\Storage;

final readonly class PageExporter
{
    public function export(PageVersion $version): ?string
    {
        $html = rescue(fn (): string => Storage::disk()->get($version->storage_key), null, false);

        if (! is_string($html)) {
            return null;
        }

        $css = $this->inlineCss();

        if ($css === '') {
            return $html;
        }

        $style = '<style>'.$css.'</style>';
        $position = stripos($html, '</head>');

        return $position === false
            ? $style.$html
            : substr($html, 0, $position).$style.substr($html, $position);
    }

    /**
     * Brand tokens, layout chrome, and component CSS are supplied here by later
     * layers. Keeping the default empty preserves the published HTML byte-for-byte.
     */
    private function inlineCss(): string
    {
        return '';
    }
}
