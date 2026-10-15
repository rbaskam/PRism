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
 * Replies to a review thread, or adds a comment to the pull request conversation.
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
            'in_reply_to' => ['nullable', 'integer'],
        ]);

        $repository = $pullRequest->repository->full_name;

        try {
            if (isset($validated['in_reply_to'])) {
                $github->replyToReviewComment($repository, $pullRequest->number, $validated['in_reply_to'], $validated['body']);
            } else {
                $github->createIssueComment($repository, $pullRequest->number, $validated['body']);
            }
        } catch (RequestException|ConnectionException $exception) {
            return back()->with('error', GithubClient::describeFailure($exception));
        }

        return back()->with('success', 'Reply posted.');
    }
}
