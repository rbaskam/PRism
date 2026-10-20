<?php

namespace App\Enums;

/**
 * The kinds of GitHub pull request comments that can be replied to.
 */
enum CommentKind: string
{
    case ReviewThread = 'review_thread';
    case Conversation = 'conversation';
}
