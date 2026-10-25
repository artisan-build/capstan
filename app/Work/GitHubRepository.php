<?php

namespace App\Work;

use InvalidArgumentException;

final class GitHubRepository
{
    public const int MAX_LENGTH = 140;

    public static function normalize(string $repository): string
    {
        $canonical = strtolower($repository);
        if (strlen($canonical) > self::MAX_LENGTH
            || preg_match('/\A(?![a-z0-9-]*--)[a-z0-9](?:[a-z0-9-]{0,37}[a-z0-9])?\/(?!\.{1,2}\z)(?![a-z0-9._-]*\.git\z)[a-z0-9._-]{1,100}\z/D', $canonical) !== 1) {
            throw new InvalidArgumentException('Invalid GitHub repository slug.');
        }

        return $canonical;
    }
}
