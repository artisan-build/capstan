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
        if ($this->isStructurallyValidTrueType($bytes)) {
            return 'font/ttf';
        }

        $this->invalid('The asset is not a structurally valid TrueType font.');
    }

    /**
     * TrueType support is deliberately structural and payload-aware. The SFNT
     * header/search fields, complete unique table directory, aligned non-
     * overlapping ranges, every table checksum, whole-file checksum adjustment,
     * required tables, and the linked glyph/metrics table sizes must all agree.
     * Compressed containers are rejected rather than trusted on header shape.
     */
    private function isStructurallyValidTrueType(string $bytes): bool
    {
        $length = strlen($bytes);
        $tables = $this->uint16($bytes, 4);
        if ($length < 28
            || ! str_starts_with($bytes, "\x00\x01\x00\x00")
            || $tables < 1
            || $tables > 128
            || ! $this->searchFieldsAreValid($bytes, $tables)) {
            return false;
        }

        $directoryEnd = 12 + ($tables * 16);
        if ($directoryEnd > $length) {
            return false;
        }

        $records = [];
        $ranges = [];
        for ($table = 0; $table < $tables; $table++) {
            $record = 12 + ($table * 16);
            $tag = substr($bytes, $record, 4);
            $checksum = $this->uint32($bytes, $record + 4);
            $offset = $this->uint32($bytes, $record + 8);
            $tableLength = $this->uint32($bytes, $record + 12);
            $paddedEnd = $offset + (($tableLength + 3) & ~3);

            if (preg_match('/^[\x20-\x7e]{4}$/D', $tag) !== 1
                || isset($records[$tag])
                || $tableLength < 1
                || $offset < $directoryEnd
                || $offset % 4 !== 0
                || $offset + $tableLength > $length
                || $paddedEnd > $length) {
                return false;
            }

            $tableBytes = substr($bytes, $offset, $tableLength);
            if ($tag === 'head') {
                if ($tableLength < 54) {
                    return false;
                }
                $tableBytes = substr_replace($tableBytes, "\0\0\0\0", 8, 4);
            }
            if ($this->checksum($tableBytes) !== $checksum) {
                return false;
            }

            $records[$tag] = ['offset' => $offset, 'length' => $tableLength];
            $ranges[] = [$offset, $paddedEnd];
        }

        usort($ranges, static fn (array $left, array $right): int => $left[0] <=> $right[0]);
        for ($index = 1; $index < count($ranges); $index++) {
            if ($ranges[$index][0] < $ranges[$index - 1][1]) {
                return false;
            }
        }

        return $this->hasValidRequiredTables($bytes, $records)
            && $this->checksum($bytes) === 0xB1B0AFBA;
    }

    private function searchFieldsAreValid(string $bytes, int $tables): bool
    {
        $power = 1;
        $selector = 0;
        while (($power * 2) <= $tables) {
            $power *= 2;
            $selector++;
        }

        return $this->uint16($bytes, 6) === $power * 16
            && $this->uint16($bytes, 8) === $selector
            && $this->uint16($bytes, 10) === ($tables * 16) - ($power * 16);
    }

    /**
     * @param  array<string, array{offset: int, length: int}>  $records
     */
    private function hasValidRequiredTables(string $bytes, array $records): bool
    {
        foreach (['OS/2', 'cmap', 'glyf', 'head', 'hhea', 'hmtx', 'loca', 'maxp', 'name', 'post'] as $required) {
            if (! isset($records[$required])) {
                return false;
            }
        }

        $head = $records['head'];
        $maxp = $records['maxp'];
        $hhea = $records['hhea'];
        $unitsPerEm = $this->uint16($bytes, $head['offset'] + 18);
        if ($this->uint32($bytes, $head['offset'] + 12) !== 0x5F0F3CF5
            || $unitsPerEm < 16
            || $unitsPerEm > 16384
            || $maxp['length'] < 6
            || $hhea['length'] < 36) {
            return false;
        }

        $glyphs = $this->uint16($bytes, $maxp['offset'] + 4);
        $metrics = $this->uint16($bytes, $hhea['offset'] + 34);
        $locaFormat = $this->int16($bytes, $head['offset'] + 50);
        if ($glyphs < 1 || $metrics < 1 || $metrics > $glyphs || ! in_array($locaFormat, [0, 1], true)) {
            return false;
        }

        $expectedLocaLength = ($glyphs + 1) * ($locaFormat === 0 ? 2 : 4);
        $minimumHmtxLength = ($metrics * 4) + (($glyphs - $metrics) * 2);
        if ($records['loca']['length'] !== $expectedLocaLength
            || $records['hmtx']['length'] < $minimumHmtxLength) {
            return false;
        }

        $lastGlyphOffset = 0;
        for ($glyph = 0; $glyph <= $glyphs; $glyph++) {
            $glyphOffset = $locaFormat === 0
                ? $this->uint16($bytes, $records['loca']['offset'] + ($glyph * 2)) * 2
                : $this->uint32($bytes, $records['loca']['offset'] + ($glyph * 4));
            if ($glyphOffset < $lastGlyphOffset || $glyphOffset > $records['glyf']['length']) {
                return false;
            }
            $lastGlyphOffset = $glyphOffset;
        }

        return $lastGlyphOffset <= $records['glyf']['length'];
    }

    private function checksum(string $bytes): int
    {
        $bytes .= str_repeat("\0", (4 - (strlen($bytes) % 4)) % 4);
        $sum = 0;
        for ($offset = 0; $offset < strlen($bytes); $offset += 4) {
            $sum = ($sum + $this->uint32($bytes, $offset)) & 0xFFFFFFFF;
        }

        return $sum;
    }

    private function int16(string $bytes, int $offset): int
    {
        $value = $this->uint16($bytes, $offset);

        return $value >= 0x8000 ? $value - 0x10000 : $value;
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
