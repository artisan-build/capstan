<?php

namespace App\Enums;

enum PageShareKind: string
{
    case Internal = 'internal';
    case External = 'external';
    case Link = 'link';
}
