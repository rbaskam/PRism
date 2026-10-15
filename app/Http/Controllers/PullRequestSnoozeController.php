<?php

namespace App\Http\Controllers;

use App\Models\PullRequest;
use App\Services\DesktopNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Snoozes a pull request: it leaves the user's lists and counts until the snooze ends or new commits are pushed.
 * Snoozing is kept in PRism only; nothing is sent to GitHub.
 */
class PullRequestSnoozeController extends Controller
{
    /**
     * How long a pull request can be snoozed for, in days.
     */
    public const DAYS = [1, 3, 7];

    public function store(Request $request, PullRequest $pullRequest, DesktopNotifier $notifier): RedirectResponse
    {
        $validated = $request->validate(['days' => ['required', 'integer', Rule::in(self::DAYS)]]);
        $days = (int) $validated['days'];

        $pullRequest->update(['snoozed_until' => now()->addDays($days)]);
        $notifier->setBadgeCount(PullRequest::query()->awaitingMyReview()->count());

        return back()->with('success', $days === 7 ? 'Snoozed for a week.' : "Snoozed for {$days} ".str('day')->plural($days).'.');
    }

    public function destroy(PullRequest $pullRequest, DesktopNotifier $notifier): RedirectResponse
    {
        $pullRequest->update(['snoozed_until' => null]);
        $notifier->setBadgeCount(PullRequest::query()->awaitingMyReview()->count());

        return back()->with('success', 'No longer snoozed.');
    }
}
