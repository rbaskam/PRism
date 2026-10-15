<?php

namespace App\Http\Controllers;

use App\Actions\LoadPullRequestFiles;
use App\Models\PullRequest;
use App\Services\Github\GithubClient;
use App\Services\Github\GithubException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Marks a file in a pull request as viewed or not viewed, in the app and on GitHub (where it is private to the user).
 */
class PullRequestFileViewController extends Controller
{
    public function update(Request $request, PullRequest $pullRequest, GithubClient $github, LoadPullRequestFiles $files): JsonResponse
    {
        $validated = $request->validate([
            'path' => ['required', 'string', 'max:1024'],
            'viewed' => ['required', 'boolean'],
            'file_sha' => ['nullable', 'string', 'max:64'],
        ]);

        if ($validated['viewed']) {
            $pullRequest->fileViews()->updateOrCreate(['path' => $validated['path']], [
                'file_sha' => $validated['file_sha'] ?? null,
                'viewed_at' => now(),
            ]);
        } else {
            $pullRequest->fileViews()->where('path', $validated['path'])->delete();
        }

        $syncedToGithub = true;

        try {
            $github->setFileViewed($pullRequest->node_id, $validated['path'], $validated['viewed']);
            $files->rememberGithubViewedState($pullRequest, $validated['path'], $validated['viewed']);
        } catch (RequestException|ConnectionException|GithubException $exception) {
            $syncedToGithub = false;
            report($exception);
        }

        return response()->json([
            'viewed' => $validated['viewed'],
            'synced_to_github' => $syncedToGithub,
        ]);
    }
}
