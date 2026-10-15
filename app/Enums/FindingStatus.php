<?php

namespace App\Enums;

enum FindingStatus: string
{
    case New = 'new';
    case Read = 'read';
    case NotUseful = 'not_useful';
    case NotBlocker = 'not_blocker';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Read => 'Read',
            self::NotUseful => 'Not useful',
            self::NotBlocker => 'Not a blocker',
        };
    }
}
