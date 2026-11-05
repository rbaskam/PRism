<?php

namespace App\Http\Controllers;

use App\Models\PullRequest;
use Illuminate\Http\Response;

/**
 * Clears the sidebar's "Recently viewed" list. The pull requests themselves stay. It answers without a page, since
 * going back to a pull request page would count as viewing it again; the sidebar reloads just the list instead.
 */
class RecentPullRequestController extends Controller
{
    public function destroy(): Response
    {
        PullRequest::withoutTimestamps(fn () => PullRequest::query()->whereNotNull('last_viewed_at')->update(['last_viewed_at' => null]));

        return response()->noContent();
    }
}
