<?php

namespace App\Http\Controllers;

use App\Enums\AiTaskStatus;
use App\Enums\CommentKind;
use App\Jobs\DraftCommentReply;
use App\Models\PullRequest;
use App\Services\AppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Asks Claude to draft a reply to a comment. Nothing is posted until the user sends it.
 */
class ReplyDraftController extends Controller
{
    public function store(Request $request, PullRequest $pullRequest, AppSettings $settings): RedirectResponse
    {
        if (! $settings->allowsReplyingToComments()) {
            return back()->with('error', 'Replying to comments is turned off in Settings → PR review.');
        }

        if (! $settings->isClaudeConfigured()) {
            return back()->with('error', 'Connect Claude in Settings → Claude to draft replies.');
        }

        $validated = $request->validate([
            'comment_node_id' => ['required', 'string', 'max:255'],
            'comment_database_id' => ['nullable', 'integer'],
            'comment_type' => ['required', Rule::enum(CommentKind::class)],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'conversation' => ['required', 'array', 'min:1', 'max:50'],
            'conversation.*.author' => ['required', 'string', 'max:255'],
            'conversation.*.body' => ['required', 'string', 'max:20000'],
            'path' => ['nullable', 'string', 'max:1024'],
            'diff_hunk' => ['nullable', 'string', 'max:20000'],
        ]);

        if (! $this->isOnPullRequest($pullRequest, CommentKind::from($validated['comment_type']), $validated['comment_node_id'])) {
            return back()->with('error', "That comment isn't on this pull request any more. Refresh the conversation and try again.");
        }

        $draft = $pullRequest->replyDrafts()->create([
            'comment_node_id' => $validated['comment_node_id'],
            'comment_database_id' => $validated['comment_database_id'] ?? null,
            'comment_type' => $validated['comment_type'],
            'status' => AiTaskStatus::Queued,
            'instructions' => $validated['instructions'] ?? null,
        ]);

        DraftCommentReply::dispatch($draft, $validated['conversation'], $validated['path'] ?? null, $validated['diff_hunk'] ?? null);

        return back()->with('success', 'Claude is drafting a reply.');
    }

    /**
     * Whether the comment is in this pull request's conversation, as last shown: in one of its review threads, or in
     * the conversation itself.
     */
    private function isOnPullRequest(PullRequest $pullRequest, CommentKind $kind, string $commentNodeId): bool
    {
        $conversation = $pullRequest->conversation ?? ['threads' => [], 'comments' => [], 'reviews' => []];

        $comments = match ($kind) {
            CommentKind::ReviewThread => array_merge([], ...array_map(fn (array $thread): array => $thread['comments'] ?? [], $conversation['threads'])),
            CommentKind::Conversation => [...$conversation['comments'], ...$conversation['reviews']],
        };

        return in_array($commentNodeId, array_column($comments, 'node_id'), true);
    }
}
