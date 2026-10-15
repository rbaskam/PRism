<?php

namespace App\Http\Controllers;

use App\Actions\LoadPullRequestFiles;
use App\Actions\RefreshPullRequest;
use App\Enums\CodeChangeStatus;
use App\Http\Resources\CodeChangeResource;
use App\Http\Resources\CodeQuestionResource;
use App\Http\Resources\PullRequestResource;
use App\Http\Resources\ReplyDraftResource;
use App\Http\Resources\ReviewFindingResource;
use App\Http\Resources\ReviewRunResource;
use App\Models\GithubAccount;
use App\Models\PullRequest;
use App\Models\ReviewFinding;
use App\Services\AppSettings;
use App\Services\Claude\PullRequestReviewPrompt;
use App\Services\Github\GithubClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Inertia\Support\Header;

class PullRequestController extends Controller
{
    public function show(Request $request, PullRequest $pullRequest, AppSettings $settings, PullRequestReviewPrompt $reviewPrompt, RefreshPullRequest $refresh, LoadPullRequestFiles $loadFiles): Response
    {
        $pullRequest->load(['repository', 'latestReviewRun'])->loadCount([
            'findings as open_findings_count' => fn ($query) => $query->whereNull('resolved_at'),
        ]);

        $findings = $pullRequest->findings()->get()->sortBy([
            fn (ReviewFinding $a, ReviewFinding $b): int => ($a->resolved_at !== null) <=> ($b->resolved_at !== null),
            fn (ReviewFinding $a, ReviewFinding $b): int => $a->severity->rank() <=> $b->severity->rank(),
            fn (ReviewFinding $a, ReviewFinding $b): int => $a->id <=> $b->id,
        ])->values();

        return Inertia::render('pull-requests/Show', [
            'pullRequest' => PullRequestResource::make($pullRequest)->resolve(),
            'bodyHtml' => $pullRequest->body_html,
            'findings' => ReviewFindingResource::collection($findings)->resolve(),
            'reviewRuns' => ReviewRunResource::collection($pullRequest->reviewRuns()->latest('id')->limit(20)->get())->resolve(),
            'replyDrafts' => ReplyDraftResource::collection($pullRequest->replyDrafts()->latest('id')->limit(50)->get())->resolve(),
            'codeQuestions' => CodeQuestionResource::collection($pullRequest->codeQuestions()->orderBy('id')->get())->resolve(),
            'codeChanges' => CodeChangeResource::collection(
                $pullRequest->codeChanges()->where('status', '!=', CodeChangeStatus::Discarded)->latest('id')->limit(10)->get(),
            )->resolve(),
            'reviewPrompt' => $reviewPrompt->prompt($pullRequest),
            'permissions' => [
                'can_run_review' => $settings->isClaudeConfigured(),
                'can_post_findings' => $settings->allowsPostingFindings(),
                'can_reply_to_comments' => $settings->allowsReplyingToComments(),
                'can_approve' => ! $pullRequest->is_authored_by_me,
                'can_change_code' => $settings->allowsCodeChanges() && $settings->isClaudeConfigured() && $pullRequest->repository->localCheckoutPath() !== null,
                'can_push' => $settings->allowsPushing(),
                'has_local_checkout' => $pullRequest->repository->localCheckoutPath() !== null,
            ],
            // Show the saved conversation straight away while nothing has changed on GitHub. A reload that
            // asks for the conversation (Refresh, or after posting a comment) always fetches it again.
            'conversation' => $pullRequest->hasCurrentConversation() && ! $this->isReloading($request, 'conversation')
                ? $pullRequest->conversation
                : Inertia::defer(function () use ($pullRequest, $refresh): array {
                    try {
                        return $refresh->handle($pullRequest, GithubAccount::current());
                    } catch (RequestException|ConnectionException $exception) {
                        return ['error' => GithubClient::describeFailure($exception), 'threads' => [], 'comments' => [], 'reviews' => []];
                    }
                }),
            // Loaded only when the Files changed tab asks for it, to save GitHub API calls on large pull requests.
            'files' => Inertia::optional(function () use ($pullRequest, $loadFiles): array {
                try {
                    return ['error' => null, 'files' => $loadFiles->handle($pullRequest)];
                } catch (RequestException|ConnectionException $exception) {
                    return ['error' => GithubClient::describeFailure($exception), 'files' => []];
                }
            }),
        ]);
    }

    private function isReloading(Request $request, string $prop): bool
    {
        return in_array($prop, explode(',', (string) $request->header(Header::PARTIAL_ONLY)), true);
    }
}
