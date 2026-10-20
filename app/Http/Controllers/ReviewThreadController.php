<?php

namespace App\Http\Controllers;

use App\Models\PullRequest;
use App\Services\AppSettings;
use App\Services\Github\GithubClient;
use App\Services\Github\GithubException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReviewThreadController extends Controller
{
    /**
     * Mark a review thread as resolved on GitHub.
     */
    public function resolve(Request $request, PullRequest $pullRequest, AppSettings $settings, GithubClient $github): RedirectResponse
    {
        if (! $settings->allowsReplyingToComments()) {
            return back()->with('error', 'Replying to comments is turned off in Settings → PR review.');
        }

        $validated = $request->validate(['thread_id' => ['required', 'string', 'max:255']]);

        try {
            $github->resolveReviewThread($validated['thread_id']);
        } catch (RequestException|ConnectionException|GithubException $exception) {
            return back()->with('error', GithubClient::describeFailure($exception));
        }

        return back()->with('success', 'Conversation resolved.');
    }
}
