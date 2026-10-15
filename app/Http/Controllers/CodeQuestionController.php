<?php

namespace App\Http\Controllers;

use App\Enums\AiTaskStatus;
use App\Jobs\AnswerCodeQuestion;
use App\Models\CodeQuestion;
use App\Models\PullRequest;
use App\Services\AppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Questions about highlighted lines of a pull request diff, answered by Claude.
 */
class CodeQuestionController extends Controller
{
    public function store(Request $request, PullRequest $pullRequest, AppSettings $settings): RedirectResponse
    {
        if (! $settings->isClaudeConfigured()) {
            return back()->with('error', 'Connect Claude in Settings → Claude to ask about code.');
        }

        $validated = $request->validate([
            'path' => ['required', 'string', 'max:1024'],
            'side' => ['required', Rule::in([CodeQuestion::SIDE_NEW, CodeQuestion::SIDE_OLD])],
            'start_line' => ['required', 'integer', 'min:1'],
            'end_line' => ['required', 'integer', 'gte:start_line'],
            'selected_code' => ['required', 'string', 'max:50000'],
            'prompt' => ['required', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'integer', Rule::exists('code_questions', 'id')->where('pull_request_id', $pullRequest->id)],
        ]);

        $parent = isset($validated['parent_id']) ? CodeQuestion::find($validated['parent_id'])->threadRoot() : null;

        $question = $pullRequest->codeQuestions()->create([
            ...$validated,
            'parent_id' => $parent?->id,
            'status' => AiTaskStatus::Queued,
            'head_sha' => $pullRequest->head_sha,
            'model' => $settings->claudeModel(),
        ]);

        AnswerCodeQuestion::dispatch($question);

        return back()->with('success', 'Claude is looking at '.$question->location().'.');
    }

    public function destroy(CodeQuestion $codeQuestion): RedirectResponse
    {
        $codeQuestion->delete();

        return back();
    }
}
