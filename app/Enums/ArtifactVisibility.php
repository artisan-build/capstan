<?php

namespace App\Enums;

enum ArtifactVisibility: string
{
    case OrgAuth = 'org_auth';
    case SignedUrl = 'signed_url';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
