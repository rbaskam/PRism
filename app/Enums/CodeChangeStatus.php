<?php

namespace App\Enums;

enum CodeChangeStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Ready = 'ready';
    case NoChanges = 'no_changes';
    case Pushing = 'pushing';
    case Pushed = 'pushed';
    case Discarded = 'discarded';
    case Failed = 'failed';

    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Running, self::Pushing], true);
    }
}
