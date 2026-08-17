<?php

namespace App\Enums;

enum MessageType: string
{
    case Handoff = 'handoff';
    case KbNomination = 'kb_nomination';
    case Solicitation = 'solicitation';
    case Generic = 'generic';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
