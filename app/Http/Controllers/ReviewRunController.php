<?php

namespace App\Http\Controllers;

use App\Actions\QueuePullRequestReview;
use App\Models\PullRequest;
use App\Models\ReviewRun;
use App\Services\AppSettings;
use Illuminate\Http\RedirectResponse;

/**
 * Starts and cancels AI reviews of a pull request.
 */
class ReviewRunController extends Controller
{
    public function store(PullRequest $pullRequest, AppSettings $settings, QueuePullRequestReview $queueReview): RedirectResponse
    {
        if (! $settings->isClaudeConfigured()) {
            return back()->with('error', 'Connect Claude in Settings → Claude to run AI reviews.');
        }

        $run = $queueReview->handle($pullRequest);

        return back()->with('success', $run->wasRecentlyCreated
            ? 'AI review started. You can keep working while Claude reviews.'
            : 'An AI review is already running for this pull request.');
    }

    /**
     * Stop a review that hasn't finished. The worker stops Claude within a second or two. A run left behind when PRism
     * quit has no worker, so this is also how the user clears it straight away.
     */
    public function cancel(ReviewRun $reviewRun): RedirectResponse
    {
        if (! $reviewRun->status->isActive()) {
            return back();
        }

        $reviewRun->cancel();

        return back()->with('success', 'AI review cancelled.');
    }
}
