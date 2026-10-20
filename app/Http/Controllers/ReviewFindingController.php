<?php

namespace App\Http\Controllers;

use App\Enums\FindingStatus;
use App\Models\PullRequest;
use App\Models\ReviewFinding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Records what the user decided about AI findings: read, not useful, not a blocker, or back to new.
 */
class ReviewFindingController extends Controller
{
    public function update(Request $request, ReviewFinding $finding): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(FindingStatus::class)],
        ]);

        $finding->update(['status' => $validated['status']]);

        return back();
    }

    public function bulkUpdate(Request $request, PullRequest $pullRequest): RedirectResponse
    {
        $validated = $request->validate([
            'finding_ids' => ['required', 'array', 'min:1'],
            'finding_ids.*' => ['integer', Rule::exists('review_findings', 'id')->where('pull_request_id', $pullRequest->id)],
            'status' => ['required', Rule::enum(FindingStatus::class)],
        ]);

        $pullRequest->findings()->whereKey($validated['finding_ids'])->update(['status' => $validated['status']]);

        return back();
    }
}
