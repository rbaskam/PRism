<?php

namespace App\Http\Controllers;

use App\Enums\CodeChangeStatus;
use App\Enums\CodeChangeTarget;
use App\Jobs\MakeCodeChanges;
use App\Jobs\PushCodeChange;
use App\Models\CodeChange;
use App\Models\PullRequest;
use App\Services\AppSettings;
use App\Services\Git\Git;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Fix with Claude": Claude changes the code in a worktree, the user reviews the diff, then pushes or discards it.
 */
class CodeChangeController extends Controller
{
    public function store(Request $request, PullRequest $pullRequest, AppSettings $settings): RedirectResponse
    {
        $checkout = $pullRequest->repository->localCheckoutPath();

        $problem = match (true) {
            ! $settings->allowsCodeChanges() => 'Letting Claude change code is turned off in Settings → PR review.',
            ! $settings->isClaudeConfigured() => 'Connect Claude in Settings → Claude first.',
            $checkout === null => "Link a local checkout of {$pullRequest->repository->full_name} in Settings → GitHub first.",
            $pullRequest->codeChanges()->whereIn('status', [CodeChangeStatus::Queued, CodeChangeStatus::Running, CodeChangeStatus::Pushing])->exists() => 'Claude is already working on this pull request.',
            default => null,
        };

        if ($problem !== null) {
            return back()->with('error', $problem);
        }

        $validated = $request->validate([
            'target' => ['required', Rule::enum(CodeChangeTarget::class)],
            'instructions' => ['nullable', 'string', 'max:5000', Rule::requiredIf(fn (): bool => blank($request->input('finding_ids')) && blank($request->input('comments')))],
            'finding_ids' => ['nullable', 'array'],
            'finding_ids.*' => ['integer', Rule::exists('review_findings', 'id')->where('pull_request_id', $pullRequest->id)],
            'comments' => ['nullable', 'array', 'max:50'],
            'comments.*.author' => ['required', 'string', 'max:255'],
            'comments.*.body' => ['required', 'string', 'max:20000'],
            'comments.*.path' => ['nullable', 'string', 'max:1024'],
            'comments.*.line' => ['nullable', 'integer'],
        ]);

        $codeChange = $pullRequest->codeChanges()->create([
            ...$validated,
            'status' => CodeChangeStatus::Queued,
            'checkout_path' => $checkout,
            'model' => $settings->claudeModel(),
        ]);

        MakeCodeChanges::dispatch($codeChange);

        return back()->with('success', 'Claude is working on the changes. You can review the diff before anything is pushed.');
    }

    public function push(CodeChange $codeChange, AppSettings $settings): RedirectResponse
    {
        if (! $settings->allowsPushing()) {
            return back()->with('error', 'Pushing is turned off in Settings → PR review.');
        }

        if ($codeChange->status !== CodeChangeStatus::Ready) {
            return back()->with('error', 'Only changes that are ready can be pushed.');
        }

        $codeChange->update(['status' => CodeChangeStatus::Pushing, 'error' => null]);
        PushCodeChange::dispatch($codeChange);

        return back()->with('success', $codeChange->target === CodeChangeTarget::NewPullRequest
            ? 'Pushing a new branch and opening a pull request…'
            : "Pushing to {$codeChange->destinationBranch()}…");
    }

    public function destroy(CodeChange $codeChange, Git $git): RedirectResponse
    {
        if ($codeChange->status->isActive()) {
            return back()->with('error', 'Wait for Claude to finish before discarding these changes.');
        }

        if ($codeChange->worktree_path !== null) {
            rescue(fn () => $git->removeWorktree($codeChange->checkout_path, $codeChange->worktree_path, $codeChange->branch), report: false);
        }

        $codeChange->update(['status' => CodeChangeStatus::Discarded]);

        return back()->with('success', 'Changes discarded.');
    }
}
