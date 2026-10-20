<?php

namespace App\Http\Controllers;

use App\Actions\PostFindingToPullRequest;
use App\Models\ReviewFinding;
use App\Services\AppSettings;
use App\Services\Github\GithubClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Writes an AI finding to the pull request on GitHub, after the user has reviewed and edited it.
 */
class PostedFindingController extends Controller
{
    public function store(Request $request, ReviewFinding $finding, AppSettings $settings, PostFindingToPullRequest $post): RedirectResponse
    {
        if (! $settings->allowsPostingFindings()) {
            return back()->with('error', 'Posting findings is turned off in Settings → PR review.');
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:65000'],
        ]);

        try {
            $post->handle($finding, $validated['body']);
        } catch (RequestException|ConnectionException $exception) {
            return back()->with('error', GithubClient::describeFailure($exception));
        }

        return back()->with('success', 'Comment added to the pull request.');
    }
}
