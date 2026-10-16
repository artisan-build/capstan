<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class BrandAssetInspector
{
    /** @return array{content_type: string, size_bytes: int, content_hash: string} */
    public function inspect(string $bytes): array
    {
        $size = strlen($bytes);
        $limit = (int) config('capstan.brand.max_image_bytes', 2 * 1024 * 1024);

        // Guard every image-byte read, including the metadata probe below.
        if ($size < 12 || $size > $limit) {
            $this->invalid('Asset bytes are too short or exceed the configured limit.');
        }

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

        return [
            'content_type' => $type,
            'size_bytes' => $size,
            'content_hash' => hash('sha256', $bytes),
        ];
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['asset' => $message]);
    }
}
