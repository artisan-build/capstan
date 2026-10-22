<?php

namespace App\Enums;

enum WorkExpirationProvenance: string
{
    case Own = 'own';
    case Ancestor = 'ancestor';
}
