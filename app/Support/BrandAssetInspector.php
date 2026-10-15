<?php

namespace App\Support;

use App\Enums\BrandAssetKind;
use Illuminate\Validation\ValidationException;

final class BrandAssetInspector
{
    /** @return array{content_type: string, size_bytes: int, content_hash: string} */
    public function inspect(BrandAssetKind $kind, string $bytes): array
    {
        $size = strlen($bytes);
        $limit = $kind === BrandAssetKind::Font
            ? (int) config('capstan.brand.max_font_bytes', 5 * 1024 * 1024)
            : (int) config('capstan.brand.max_image_bytes', 2 * 1024 * 1024);

        if ($size === 0 || $size > $limit) {
            $this->invalid('Asset bytes are empty or exceed the configured limit.');
        }

        $contentType = $kind === BrandAssetKind::Font
            ? $this->fontType($bytes)
            : $this->imageType($bytes);

        return [
            'content_type' => $contentType,
            'size_bytes' => $size,
            'content_hash' => hash('sha256', $bytes),
        ];
    }

    private function imageType(string $bytes): string
    {
        $image = @getimagesizefromstring($bytes);
        $type = is_array($image) ? $image['mime'] : null;

        if (! is_string($type) || ! in_array($type, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            $this->invalid('The asset is not a decodable PNG, JPEG, or WebP image.');
        }

        return $type;
    }

    private function fontType(string $bytes): string
    {
        $length = strlen($bytes);
        $magic = substr($bytes, 0, 4);

        if ($magic === 'wOFF' && $length >= 44 && $this->uint32($bytes, 8) === $length && $this->uint16($bytes, 12) > 0) {
            return 'font/woff';
        }
        if ($magic === 'wOF2' && $length >= 48 && $this->uint32($bytes, 8) === $length && $this->uint16($bytes, 12) > 0) {
            return 'font/woff2';
        }
        if (in_array($magic, ["\x00\x01\x00\x00", 'OTTO'], true) && $length >= 12) {
            $tables = $this->uint16($bytes, 4);
            if ($tables > 0 && $length >= 12 + ($tables * 16)) {
                return $magic === 'OTTO' ? 'font/otf' : 'font/ttf';
            }
        }

        $this->invalid('The asset is not a structurally valid WOFF, WOFF2, TTF, or OTF font.');
    }

    private function uint16(string $bytes, int $offset): int
    {
        return (int) (unpack('nvalue', substr($bytes, $offset, 2))['value'] ?? 0);
    }

    private function uint32(string $bytes, int $offset): int
    {
        return (int) (unpack('Nvalue', substr($bytes, $offset, 4))['value'] ?? 0);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['asset' => $message]);
    }
}
