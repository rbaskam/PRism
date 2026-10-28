<?php

namespace App\Http\Controllers;

use App\Models\PendingReviewComment;
use App\Models\PullRequest;
use App\Services\AppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Comments on lines that wait in the app until the user submits their review, like a pending review on GitHub.
 * Nothing is sent to GitHub here: the review submission posts them (see SubmitPullRequestReview).
 */
class PendingReviewCommentController extends Controller
{
    public function store(Request $request, PullRequest $pullRequest, AppSettings $settings): RedirectResponse
    {
        if (! $settings->allowsReplyingToComments()) {
            return back()->with('error', 'Commenting is turned off in Settings → PR review.');
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:65000'],
            'path' => ['required', 'string', 'max:4096'],
            'side' => ['required', 'in:LEFT,RIGHT'],
            'line' => ['required', 'integer', 'min:1'],
            'start_line' => ['nullable', 'integer', 'min:1', 'lte:line'],
        ]);

        $pullRequest->pendingReviewComments()->create($validated);

        return back()->with('success', 'Added to your review. It’s posted when you submit the review.');
    }

    public function update(Request $request, PendingReviewComment $pendingComment): RedirectResponse
    {
        $pendingComment->update($request->validate([
            'body' => ['required', 'string', 'max:65000'],
        ]));

        return back();
    }

    public function destroy(PendingReviewComment $pendingComment): RedirectResponse
    {
        $pendingComment->delete();

        return back()->with('success', 'Comment removed from your review.');
    }
}
