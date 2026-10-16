<?php

namespace App\Support;

use InvalidArgumentException;

final class BrandCssGrammar
{
    public function assertValid(string $css): void
    {
        // Deliberately reject escape/comment obfuscation, all at-rules and URL
        // functions. This is a local declaration/rule block, not a CSS loader.
        if (preg_match('/[<>\\\\@]|\/\*|url\s*\(|image-set\s*\(|expression\s*\(/i', $css) === 1) {
            throw new InvalidArgumentException('must not contain markup, escapes, comments, at-rules or resource functions');
        }
        preg_match_all('/([a-zA-Z_-][a-zA-Z0-9_-]*)\s*\(/', $css, $functions);
        foreach ($functions[1] as $function) {
            if (! in_array(strtolower($function), ['var', 'rgb', 'rgba', 'hsl', 'hsla', 'calc', 'min', 'max', 'clamp', 'repeat', 'minmax'], true)) {
                throw new InvalidArgumentException('contains an unsupported CSS function');
            }
        }
    }
}
