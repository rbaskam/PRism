<?php

namespace App\Http\Controllers;

use App\Actions\SubmitPullRequestReview;
use App\Enums\SubmitReviewEvent;
use App\Models\PullRequest;
use App\Services\AppSettings;
use App\Services\Github\GithubClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Approves, requests changes on, or comments on a pull request.
 */
class PullRequestReviewController extends Controller
{
    public function store(Request $request, PullRequest $pullRequest, AppSettings $settings, SubmitPullRequestReview $submit): RedirectResponse
    {
        $validated = $request->validate([
            'event' => ['required', Rule::enum(SubmitReviewEvent::class)],
            'body' => [
                'nullable',
                'string',
                'max:65000',
                Rule::requiredIf(fn (): bool => $request->input('event') === SubmitReviewEvent::RequestChanges->value
                    || ($request->input('event') === SubmitReviewEvent::Comment->value && blank($request->input('finding_ids')))),
            ],
            'finding_ids' => ['nullable', 'array'],
            'finding_ids.*' => ['integer', Rule::exists('review_findings', 'id')->where('pull_request_id', $pullRequest->id)],
        ]);

        $event = SubmitReviewEvent::from($validated['event']);
        $findingIds = $validated['finding_ids'] ?? [];

        if ($findingIds !== [] && ! $settings->allowsPostingFindings()) {
            return back()->with('error', 'Posting findings is turned off in Settings → PR review.');
        }

        if ($pullRequest->is_authored_by_me && $event !== SubmitReviewEvent::Comment) {
            return back()->with('error', 'GitHub does not let you approve or request changes on your own pull request.');
        }

        try {
            $submit->handle($pullRequest, $event, $validated['body'] ?? null, $pullRequest->findings()->whereKey($findingIds)->get());
        } catch (RequestException|ConnectionException $exception) {
            return back()->with('error', GithubClient::describeFailure($exception));
        }

        return back()->with('success', match ($event) {
            SubmitReviewEvent::Approve => 'Pull request approved.',
            SubmitReviewEvent::RequestChanges => 'Changes requested.',
            SubmitReviewEvent::Comment => 'Review comment submitted.',
        });
    }
}
