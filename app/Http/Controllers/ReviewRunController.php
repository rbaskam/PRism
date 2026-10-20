<?php

namespace App\Http\Controllers;

use App\Actions\QueuePullRequestReview;
use App\Models\PullRequest;
use App\Services\AppSettings;
use Illuminate\Http\RedirectResponse;

/**
 * Starts an AI review of a pull request.
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
}
