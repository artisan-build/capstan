<?php

namespace App\Support;

/**
 * Colours arrive already validated as hex by BrandKitSchema, so they are safe
 * as CSS values. Image URLs, when present, point at the render origin's
 * applied-asset route.
 */
final readonly class AppBrandValues
{
    public function __construct(
        public string $displayName,
        public ?string $accentLight,
        public ?string $accentDark,
        public ?string $markUrl,
        public ?string $logoLightUrl,
        public ?string $logoDarkUrl,
    ) {}

    /** The square icon for light surfaces: the mark first, then the light logo. */
    public function iconLightUrl(): ?string
    {
        return $this->markUrl ?? $this->logoLightUrl;
    }

    /** The square icon for dark surfaces: the mark first, then the dark logo. */
    public function iconDarkUrl(): ?string
    {
        return $this->markUrl ?? $this->logoDarkUrl;
    }

    /** The wide image for mail headers: the light logo first, then the mark. */
    public function mailLogoUrl(): ?string
    {
        return $this->logoLightUrl ?? $this->markUrl;
    }

    public function hasAccent(): bool
    {
        return $this->accentLight !== null && $this->accentDark !== null;
    }

    /** Readable text on top of a hex colour, by WCAG relative luminance. */
    public static function foregroundFor(string $hex): string
    {
        $digits = ltrim($hex, '#');
        if (strlen($digits) <= 4) {
            $digits = implode('', array_map(static fn (string $digit): string => $digit.$digit, str_split($digits)));
        }
        $channels = array_map(static function (string $pair): float {
            $value = hexdec($pair) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($digits, 0, 6), 2));
        $luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

        return $luminance > 0.179 ? '#171717' : '#ffffff';
    }
}
