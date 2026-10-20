<?php

namespace App\Enums;

/**
 * The review events GitHub accepts when submitting a pull request review.
 */
enum SubmitReviewEvent: string
{
    case Approve = 'APPROVE';
    case RequestChanges = 'REQUEST_CHANGES';
    case Comment = 'COMMENT';
}
