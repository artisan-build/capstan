<?php

namespace App\Support;

use App\Enums\PageLayout;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class BrandKitSchema
{
    /** @var list<string> */
    public const array COLOUR_TOKENS = [
        'primary', 'accent', 'surface', 'surface_muted', 'text', 'text_muted',
        'border', 'success', 'warning', 'danger', 'chart_1', 'chart_2', 'chart_3',
        'chart_4', 'chart_5',
    ];

    /** @var list<string> */
    public const array SPACING_TOKENS = ['xs', 'sm', 'md', 'lg', 'xl'];

    /** @var list<string> */
    public const array RADIUS_TOKENS = ['sm', 'md', 'lg', 'pill'];

    /** @var list<string> */
    public const array FONT_STACKS = ['system-sans', 'system-serif', 'monospace'];

    public function __construct(
        private readonly BrandChromeSanitizer $chrome,
        private readonly BrandCssGrammar $cssGrammar,
    ) {}

    /**
     * The supported CSS grammar is deliberately small: colours are #rgb,
     * #rgba, #rrggbb or #rrggbbaa; lengths are zero or a non-negative decimal
     * followed by px, rem, em or %. Callers never supply CSS declarations.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'display_name' => (string) config('app.name', 'Capstan'),
            'colours' => [
                'light' => [
                    'primary' => '#2563eb', 'accent' => '#7c3aed', 'surface' => '#ffffff',
                    'surface_muted' => '#f8fafc', 'text' => '#0f172a', 'text_muted' => '#475569',
                    'border' => '#cbd5e1', 'success' => '#15803d', 'warning' => '#a16207',
                    'danger' => '#b91c1c', 'chart_1' => '#2563eb', 'chart_2' => '#7c3aed',
                    'chart_3' => '#0891b2', 'chart_4' => '#15803d', 'chart_5' => '#a16207',
                ],
                'dark' => [
                    'primary' => '#60a5fa', 'accent' => '#a78bfa', 'surface' => '#0f172a',
                    'surface_muted' => '#1e293b', 'text' => '#f8fafc', 'text_muted' => '#cbd5e1',
                    'border' => '#475569', 'success' => '#4ade80', 'warning' => '#facc15',
                    'danger' => '#f87171', 'chart_1' => '#60a5fa', 'chart_2' => '#a78bfa',
                    'chart_3' => '#22d3ee', 'chart_4' => '#4ade80', 'chart_5' => '#facc15',
                ],
            ],
            'spacing' => ['xs' => '0.25rem', 'sm' => '0.5rem', 'md' => '1rem', 'lg' => '1.5rem', 'xl' => '2rem'],
            'radius' => ['sm' => '0.25rem', 'md' => '0.5rem', 'lg' => '0.75rem', 'pill' => '9999px'],
            'fonts' => [
                'heading' => ['type' => 'stack', 'value' => 'system-sans'],
                'body' => ['type' => 'stack', 'value' => 'system-sans'],
            ],
            'assets' => ['logo_light' => null, 'logo_dark' => null, 'mark' => null],
            'tone_notes' => '',
            'default_layout' => 'report',
            'header_html' => '',
            'footer_html' => '',
            'custom_css' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function mergeAndNormalize(array $base, array $changes): array
    {
        $this->rejectUnknownKeys($changes, $this->defaults());

        return $this->normalize(array_replace_recursive($base, $changes));
    }

    /** @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    public function normalize(array $value): array
    {
        // Snapshots written before chrome existed keep deterministic defaults.
        $value = array_replace(array_intersect_key($this->defaults(), array_flip(['default_layout', 'header_html', 'footer_html', 'custom_css'])), $value);
        $this->assertExactKeys($value, $this->defaults(), 'brand kit');

        $displayName = $this->text($value['display_name'], 'display_name', 120, false);
        $toneNotes = $this->text($value['tone_notes'], 'tone_notes', 10_000, true);
        if (! is_string($value['default_layout']) || ! in_array($value['default_layout'], PageLayout::values(), true)) {
            $this->invalid('default_layout', 'must select a supported layout');
        }
        $colours = $this->mapGroups($value['colours'], ['light', 'dark'], self::COLOUR_TOKENS, fn (mixed $token, string $path): string => $this->colour($token, $path), 'colours');
        $spacing = $this->mapTokens($value['spacing'], self::SPACING_TOKENS, fn (mixed $token, string $path): string => $this->length($token, $path), 'spacing');
        $radius = $this->mapTokens($value['radius'], self::RADIUS_TOKENS, fn (mixed $token, string $path): string => $this->length($token, $path), 'radius');

        if (! is_array($value['fonts'])) {
            $this->invalid('fonts', 'must be an object');
        }
        $this->assertExactKeys($value['fonts'], ['heading' => [], 'body' => []], 'fonts');
        $fonts = [
            'heading' => $this->font($value['fonts']['heading'], 'fonts.heading'),
            'body' => $this->font($value['fonts']['body'], 'fonts.body'),
        ];

        if (! is_array($value['assets'])) {
            $this->invalid('assets', 'must be an object');
        }
        $this->assertExactKeys($value['assets'], ['logo_light' => null, 'logo_dark' => null, 'mark' => null], 'assets');
        $assets = [];
        foreach (['logo_light', 'logo_dark', 'mark'] as $slot) {
            $asset = $value['assets'][$slot];
            if ($asset !== null && (! is_string($asset) || ! Str::isUuid($asset))) {
                $this->invalid('assets.'.$slot, 'must be null or an asset UUID');
            }
            $assets[$slot] = $asset;
        }

        $selectedAssetIds = array_values(array_filter($assets, is_string(...)));
        $header = $this->chrome($value['header_html'], 'header_html', $selectedAssetIds);
        $footer = $this->chrome($value['footer_html'], 'footer_html', $selectedAssetIds);
        $customCss = $this->css($value['custom_css']);

        return [
            'display_name' => $displayName,
            'colours' => $colours,
            'spacing' => $spacing,
            'radius' => $radius,
            'fonts' => $fonts,
            'assets' => $assets,
            'tone_notes' => $toneNotes,
            'default_layout' => $value['default_layout'],
            'header_html' => $header,
            'footer_html' => $footer,
            'custom_css' => $customCss,
        ];
    }

    /** @param list<string> $selectedAssetIds */
    private function chrome(mixed $value, string $path, array $selectedAssetIds): string
    {
        $html = $this->text($value, $path, 32_768, true);
        try {
            $sanitized = $this->chrome->sanitize($html, $selectedAssetIds);
            if (mb_strlen($sanitized) > 32_768) {
                $this->invalid($path, 'must remain within 32,768 characters after normalization');
            }

            return $sanitized;
        } catch (InvalidArgumentException $exception) {
            $this->invalid($path, $exception->getMessage());
        }
    }

    private function css(mixed $value): string
    {
        $css = $this->text($value, 'custom_css', 65_536, true);
        try {
            $this->cssGrammar->assertValid($css);
        } catch (InvalidArgumentException $exception) {
            $this->invalid('custom_css', $exception->getMessage());
        }

        return $css;
    }

    /**
     * @return array{type: string, value: string}
     */
    private function font(mixed $value, string $path): array
    {
        if (! is_array($value)) {
            $this->invalid($path, 'must be an object');
        }
        $this->assertExactKeys($value, ['type' => '', 'value' => ''], $path);
        $type = $value['type'] ?? null;
        $choice = $value['value'] ?? null;
        if ($type === 'stack' && is_string($choice) && in_array($choice, self::FONT_STACKS, true)) {
            return ['type' => 'stack', 'value' => $choice];
        }
        $this->invalid($path, 'must select an application-owned font stack');
    }

    private function colour(mixed $value, string $path): string
    {
        if (! is_string($value) || preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/D', $value) !== 1) {
            $this->invalid($path, 'must be a hexadecimal CSS colour');
        }

        return mb_strtolower($value);
    }

    private function length(mixed $value, string $path): string
    {
        if (! is_string($value) || preg_match('/^(?:0|(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:px|rem|em|%))$/D', $value) !== 1) {
            $this->invalid($path, 'must be zero or a non-negative px, rem, em or % length');
        }

        return $value;
    }

    private function text(mixed $value, string $path, int $max, bool $empty): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0") || mb_strlen($value) > $max) {
            $this->invalid($path, 'must be valid bounded UTF-8 text');
        }
        $value = trim($value);
        if (! $empty && $value === '') {
            $this->invalid($path, 'must not be empty');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $actual
     * @param  array<string, mixed>  $expected
     */
    private function assertExactKeys(array $actual, array $expected, string $path): void
    {
        $actualKeys = array_keys($actual);
        $expectedKeys = array_keys($expected);
        sort($actualKeys);
        sort($expectedKeys);
        if ($actualKeys !== $expectedKeys) {
            $this->invalid($path, 'contains missing or unknown fields');
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $schema
     */
    private function rejectUnknownKeys(array $changes, array $schema, string $path = ''): void
    {
        foreach ($changes as $key => $value) {
            if (! array_key_exists($key, $schema)) {
                $this->invalid(ltrim($path.'.'.$key, '.'), 'is not a recognized brand field or token');
            }
            if (is_array($value) && is_array($schema[$key])) {
                $this->rejectUnknownKeys($value, $schema[$key], ltrim($path.'.'.$key, '.'));
            }
        }
    }

    /** @param list<string> $groups
     * @param  list<string>  $tokens
     * @return array<string, array<string, string>>
     */
    private function mapGroups(mixed $value, array $groups, array $tokens, callable $normalizer, string $path): array
    {
        if (! is_array($value)) {
            $this->invalid($path, 'must be an object');
        }
        $this->assertExactKeys($value, array_fill_keys($groups, []), $path);
        $result = [];
        foreach ($groups as $group) {
            $result[$group] = $this->mapTokens($value[$group], $tokens, $normalizer, $path.'.'.$group);
        }

        return $result;
    }

    /** @param list<string> $tokens
     * @return array<string, string>
     */
    private function mapTokens(mixed $value, array $tokens, callable $normalizer, string $path): array
    {
        if (! is_array($value)) {
            $this->invalid($path, 'must be an object');
        }
        $this->assertExactKeys($value, array_fill_keys($tokens, ''), $path);
        $result = [];
        foreach ($tokens as $token) {
            $result[$token] = $normalizer($value[$token], $path.'.'.$token);
        }

        return $result;
    }

    private function invalid(string $path, string $message): never
    {
        throw ValidationException::withMessages([$path => $message]);
    }
}
