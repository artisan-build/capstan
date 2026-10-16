<?php

namespace App\Support;

use App\Enums\PageLayout;

final readonly class BrandContract
{
    public function __construct(private PageComponentStylesheet $components) {}

    /** @return list<array{name: string, purpose: string, chrome: bool}> */
    public function layouts(): array
    {
        $purposes = [
            PageLayout::Plain->value => ['Unwrapped content for fully custom documents.', false],
            PageLayout::Report->value => ['Long-form reports with generous document spacing.', true],
            PageLayout::Dashboard->value => ['Dense KPI tiles, tables, and responsive card grids.', true],
            PageLayout::Brief->value => ['Compact one-page summaries and executive briefs.', true],
        ];

        return array_map(
            fn (PageLayout $layout): array => [
                'name' => $layout->value,
                'purpose' => $purposes[$layout->value][0],
                'chrome' => $purposes[$layout->value][1],
            ],
            PageLayout::cases(),
        );
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        return [
            'colour_tokens' => BrandKitSchema::COLOUR_TOKENS,
            'spacing_tokens' => BrandKitSchema::SPACING_TOKENS,
            'radius_tokens' => BrandKitSchema::RADIUS_TOKENS,
            'font_stacks' => BrandKitSchema::FONT_STACKS,
            'layouts' => $this->layouts(),
            'component_classes' => array_keys($this->components->rules()),
            'assets' => [
                'slots' => [
                    'logo_light' => 'logo',
                    'logo_dark' => 'logo',
                    'mark' => 'mark',
                ],
                'accepted_types' => ['image/png', 'image/jpeg', 'image/webp'],
                'maximum_bytes' => (int) config('capstan.brand.max_image_bytes', 2 * 1024 * 1024),
                'mcp_maximum_bytes' => (int) config('capstan.brand.max_mcp_image_bytes', 700 * 1024),
                'fonts_supported' => false,
            ],
            'authoring_contract' => [
                'Use only the documented tokens, layouts, and component classes.',
                'Write complete HTML content; Capstan wraps non-plain layouts with applied brand chrome.',
                'Use /brand-assets/{selected-asset-uuid} only for an image selected in the kit.',
                'Draft values are preview-only until apply_brand_draft succeeds.',
                'Header, footer, and custom CSS run only on the isolated render origin.',
            ],
        ];
    }
}
