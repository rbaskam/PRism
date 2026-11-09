<?php

namespace App\Enums;

enum AiTaskStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    /** The user stopped it before it finished. Only AI reviews can be cancelled. */
    case Cancelled = 'cancelled';

    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Running], true);
    }
}
