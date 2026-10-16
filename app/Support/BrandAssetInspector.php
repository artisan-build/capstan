<?php

namespace App\Support;

use App\Enums\BrandAssetKind;
use Illuminate\Validation\ValidationException;

final class BrandAssetInspector
{
    /** @var list<string> */
    private const array WOFF2_KNOWN_TAGS = [
        'cmap', 'head', 'hhea', 'hmtx', 'maxp', 'name', 'OS/2', 'post',
        'cvt ', 'fpgm', 'glyf', 'loca', 'prep', 'CFF ', 'VORG', 'EBDT',
        'EBLC', 'gasp', 'hdmx', 'kern', 'LTSH', 'PCLT', 'VDMX', 'vhea',
        'vmtx', 'BASE', 'GDEF', 'GPOS', 'GSUB', 'EBSC', 'JSTF', 'MATH',
        'CBDT', 'CBLC', 'COLR', 'CPAL', 'SVG ', 'sbix', 'acnt', 'avar',
        'bdat', 'bloc', 'bsln', 'cvar', 'fdsc', 'feat', 'fmtx', 'fvar',
        'gvar', 'hsty', 'just', 'lcar', 'mort', 'morx', 'opbd', 'prop',
        'trak', 'Zapf', 'Silf', 'Glat', 'Gloc', 'Feat', 'Sill',
    ];

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

        if (! is_string($type)
            || ! in_array($type, ['image/png', 'image/jpeg', 'image/webp'], true)
            || ! function_exists('imagecreatefromstring')) {
            $this->invalid('The asset is not a decodable PNG, JPEG, or WebP image.');
        }

        $decoded = @imagecreatefromstring($bytes);
        if ($decoded === false) {
            $this->invalid('The asset is not a decodable PNG, JPEG, or WebP image.');
        }
        imagedestroy($decoded);

        return $type;
    }

    private function fontType(string $bytes): string
    {
        if ($this->isStructurallyValidWoff2($bytes)) {
            return 'font/woff2';
        }

        $this->invalid('The asset is not a structurally valid WOFF2 font.');
    }

    /**
     * WOFF2 support is deliberately structural: exact header length, a complete
     * unique table directory with canonical UIntBase128 lengths, legal transform
     * flags, the reconstructed SFNT size, core font tables, and bounded non-empty
     * compressed/metadata/private payloads must all agree. Other font containers
     * are rejected rather than passed to a browser parser on magic bytes alone.
     */
    private function isStructurallyValidWoff2(string $bytes): bool
    {
        $length = strlen($bytes);
        $tables = $this->uint16($bytes, 12);
        $totalSfntSize = $this->uint32($bytes, 16);
        $compressedSize = $this->uint32($bytes, 20);

        if ($length < 48
            || substr($bytes, 0, 4) !== 'wOF2'
            || $this->uint32($bytes, 8) !== $length
            || $tables < 1
            || $tables > 63
            || $this->uint16($bytes, 14) !== 0
            || $compressedSize < 1) {
            return false;
        }

        $cursor = 48;
        $tags = [];
        $reconstructedSize = 12 + ($tables * 16);

        for ($table = 0; $table < $tables; $table++) {
            if ($cursor >= $length) {
                return false;
            }
            $flags = ord($bytes[$cursor++]);
            $tagIndex = $flags & 0x3F;
            $transformVersion = $flags >> 6;
            if ($tagIndex === 63) {
                if ($cursor + 4 > $length) {
                    return false;
                }
                $tag = substr($bytes, $cursor, 4);
                $cursor += 4;
            } else {
                $tag = self::WOFF2_KNOWN_TAGS[$tagIndex];
            }
            if ($tag === '' || isset($tags[$tag])) {
                return false;
            }
            $tags[$tag] = true;

            $originalLength = $this->base128($bytes, $cursor);
            if ($originalLength === null || $originalLength < 1) {
                return false;
            }
            $reconstructedSize += ($originalLength + 3) & ~3;

            $glyfOrLoca = in_array($tag, ['glyf', 'loca'], true);
            if ($glyfOrLoca && ! in_array($transformVersion, [0, 3], true)) {
                return false;
            }
            if (($glyfOrLoca && $transformVersion === 0) || (! $glyfOrLoca && $transformVersion !== 0)) {
                $transformedLength = $this->base128($bytes, $cursor);
                if ($transformedLength === null || ($tag !== 'loca' && $transformedLength < 1)) {
                    return false;
                }
            }
        }

        if ($reconstructedSize !== $totalSfntSize
            || ! $this->hasRequiredFontTables($tags)
            || $cursor + $compressedSize > $length
            || trim(substr($bytes, $cursor, $compressedSize), "\0") === '') {
            return false;
        }

        $payloadEnd = $cursor + $compressedSize;
        $metadataOffset = $this->uint32($bytes, 28);
        $metadataLength = $this->uint32($bytes, 32);
        $metadataOriginalLength = $this->uint32($bytes, 36);
        $privateOffset = $this->uint32($bytes, 40);
        $privateLength = $this->uint32($bytes, 44);
        $nextBlock = $length;
        if ($metadataOffset > 0) {
            $nextBlock = min($nextBlock, $metadataOffset);
        }
        if ($privateOffset > 0) {
            $nextBlock = min($nextBlock, $privateOffset);
        }

        return $nextBlock >= $payloadEnd
            && $nextBlock - $payloadEnd <= 3
            && (($metadataOffset === 0 && $metadataLength === 0 && $metadataOriginalLength === 0)
                || ($metadataOffset >= $payloadEnd
                    && $metadataLength > 0
                    && $metadataOriginalLength > 0
                    && $metadataOffset + $metadataLength <= $length))
            && (($privateOffset === 0 && $privateLength === 0)
                || ($privateOffset >= $payloadEnd
                    && $privateLength > 0
                    && $privateOffset + $privateLength <= $length))
            && ($metadataOffset === 0
                || $privateOffset === 0
                || $privateOffset >= $metadataOffset + $metadataLength);
    }

    /** @param array<string, true> $tags */
    private function hasRequiredFontTables(array $tags): bool
    {
        foreach (['cmap', 'head', 'hhea', 'hmtx', 'maxp', 'name'] as $required) {
            if (! isset($tags[$required])) {
                return false;
            }
        }

        return (isset($tags['glyf']) && isset($tags['loca'])) || isset($tags['CFF ']);
    }

    private function base128(string $bytes, int &$cursor): ?int
    {
        $result = 0;
        for ($index = 0; $index < 5; $index++) {
            if ($cursor >= strlen($bytes)) {
                return null;
            }
            $byte = ord($bytes[$cursor++]);
            if ($index === 0 && $byte === 0x80) {
                return null;
            }
            if (($result & 0xFE000000) !== 0) {
                return null;
            }
            $result = ($result << 7) | ($byte & 0x7F);
            if (($byte & 0x80) === 0) {
                return $result;
            }
        }

        return null;
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
