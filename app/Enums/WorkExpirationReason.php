<?php

namespace App\Enums;

enum WorkExpirationReason: string
{
    case OwnExpiry = 'own_expiry';
    case AncestorExpiry = 'ancestor_expiry';
}
