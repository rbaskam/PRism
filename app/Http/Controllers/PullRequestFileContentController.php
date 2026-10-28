<?php

namespace App\Http\Controllers;

use App\Models\PullRequest;
use App\Services\Github\GithubClient;
use App\Services\Github\GithubException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The full text of a changed file, so Files changed can show the unchanged lines around the diff. The file is read
 * by its blob SHA from the pull request's file list, which is exactly the version the diff was made against.
 */
class PullRequestFileContentController extends Controller
{
    /** Files bigger than this aren't expanded: the diff stays as GitHub sent it. */
    public const MAX_BYTES = 1_000_000;

    public function __invoke(Request $request, PullRequest $pullRequest, GithubClient $github): JsonResponse
    {
        $validated = $request->validate([
            'sha' => ['required', 'string', 'regex:/^[0-9a-f]{40}$/'],
        ]);

        try {
            $content = $github->blob($pullRequest->repository->full_name, $validated['sha'], self::MAX_BYTES);
        } catch (RequestException|ConnectionException|GithubException $exception) {
            return response()->json(['message' => GithubClient::describeFailure($exception)], 502);
        }

        if ($content === null) {
            return response()->json(['message' => 'This file is too large or not text, so the lines around the changes can’t be shown.'], 422);
        }

        return response()->json(['content' => $content]);
    }
}
