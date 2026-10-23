<?php

namespace App\Http\Controllers;

use App\Models\PullRequest;
use App\Services\AppSettings;
use App\Services\Github\GithubClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Replies to a review thread, comments on lines in the diff, or adds a comment to the pull request conversation.
 */
class PullRequestCommentController extends Controller
{
    public function store(Request $request, PullRequest $pullRequest, AppSettings $settings, GithubClient $github): RedirectResponse
    {
        if (! $settings->allowsReplyingToComments()) {
            return back()->with('error', 'Replying to comments is turned off in Settings → PR review.');
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:65000'],
            'in_reply_to' => ['nullable', 'integer', 'prohibits:path'],
            'path' => ['nullable', 'string', 'max:4096'],
            'side' => ['required_with:path', 'in:LEFT,RIGHT'],
            'line' => ['required_with:path', 'integer', 'min:1'],
            'start_line' => ['nullable', 'integer', 'min:1', 'lte:line'],
        ]);

        $repository = $pullRequest->repository->full_name;
        $isLineComment = isset($validated['path']);

        if ($isLineComment && $pullRequest->head_sha === null) {
            return back()->with('error', 'This pull request has no commits to comment on yet. Sync with GitHub and try again.');
        }

        try {
            if (isset($validated['in_reply_to'])) {
                $github->replyToReviewComment($repository, $pullRequest->number, $validated['in_reply_to'], $validated['body']);
            } elseif ($isLineComment) {
                $github->createReviewComment(
                    $repository,
                    $pullRequest->number,
                    (string) $pullRequest->head_sha,
                    $validated['path'],
                    $validated['line'],
                    $validated['body'],
                    $validated['side'],
                    $validated['start_line'] ?? null,
                );
            } else {
                $github->createIssueComment($repository, $pullRequest->number, $validated['body']);
            }
        } catch (RequestException|ConnectionException $exception) {
            return back()->with('error', GithubClient::describeFailure($exception));
        }

        return back()->with('success', $isLineComment ? 'Comment added.' : 'Reply posted.');
    }
}
