<?php

namespace App\Enums;

/**
 * Where a pull request stands from the connected GitHub user's point of view.
 */
enum ReviewAttention: string
{
    case AwaitingMyReview = 'awaiting';
    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';
    case Commented = 'commented';
    case Assigned = 'assigned';
    case Mentioned = 'mentioned';
    case Authored = 'authored';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingMyReview => 'Awaiting your review',
            self::Approved => 'Approved by you',
            self::ChangesRequested => 'You requested changes',
            self::Commented => 'You commented',
            self::Assigned => 'Assigned to you',
            self::Mentioned => 'Mentioned you',
            self::Authored => 'Your pull request',
            self::None => 'Not involved',
        };
    }
}
