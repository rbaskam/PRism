<?php

namespace App\Enums;

/**
 * The state of a submitted GitHub pull request review.
 */
enum ReviewState: string
{
    case Approved = 'APPROVED';
    case ChangesRequested = 'CHANGES_REQUESTED';
    case Commented = 'COMMENTED';
    case Dismissed = 'DISMISSED';
    case Pending = 'PENDING';
}
