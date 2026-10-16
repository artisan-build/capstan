<?php

namespace App\Support;

final readonly class BrandStylesheet
{
    public function __construct(private BrandKitService $brands) {}

    public function current(): string
    {
        return $this->serialize($this->brands->current());
    }

    /** @param  array<string, mixed>  $brand */
    public function serialize(array $brand): string
    {
        $fontFaces = [];
        $fontFamilies = [];
        foreach (['heading', 'body'] as $slot) {
            $choice = $brand['fonts'][$slot];
            if ($choice['type'] === 'asset') {
                $family = 'capstan-brand-'.str_replace('-', '', $choice['value']);
                $fontFamilies[$slot] = "'{$family}'";
                $fontFaces[$choice['value']] = "@font-face{font-family:'{$family}';src:url('/brand-assets/{$choice['value']}');font-display:swap;}";
            } else {
                $fontFamilies[$slot] = match ($choice['value']) {
                    'system-serif' => 'ui-serif,Georgia,Cambria,"Times New Roman",Times,serif',
                    'monospace' => 'ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace',
                    default => 'ui-sans-serif,system-ui,sans-serif',
                };
            }
        }

        $light = $this->tokenDeclarations($brand, 'light', $fontFamilies);
        $dark = $this->colourDeclarations($brand['colours']['dark']);
        $dark .= '--capstan-logo:'.$this->assetUrl($brand['assets']['logo_dark'] ?? $brand['assets']['logo_light']).';';

        return implode('', $fontFaces).":root{{$light}}@media (prefers-color-scheme:dark){:root{{$dark}}}";
    }

    /** @param array<string, mixed> $brand
     * @param  array<string, string>  $fontFamilies
     */
    private function tokenDeclarations(array $brand, string $mode, array $fontFamilies): string
    {
        $css = $this->colourDeclarations($brand['colours'][$mode]);
        foreach ($brand['spacing'] as $name => $value) {
            $css .= '--capstan-space-'.str_replace('_', '-', $name).':'.$value.';';
        }
        foreach ($brand['radius'] as $name => $value) {
            $css .= '--capstan-radius-'.str_replace('_', '-', $name).':'.$value.';';
        }

        return $css
            .'--capstan-font-heading:'.$fontFamilies['heading'].';'
            .'--capstan-font-body:'.$fontFamilies['body'].';'
            .'--capstan-logo:'.$this->assetUrl($brand['assets']['logo_light']).';'
            .'--capstan-mark:'.$this->assetUrl($brand['assets']['mark']).';';
    }

    /** @param array<string, string> $colours */
    private function colourDeclarations(array $colours): string
    {
        $css = '';
        foreach ($colours as $name => $value) {
            $css .= '--capstan-color-'.str_replace('_', '-', $name).':'.$value.';';
        }

        return $css;
    }

    private function assetUrl(?string $id): string
    {
        return $id === null ? 'none' : "url('/brand-assets/{$id}')";
    }
}
