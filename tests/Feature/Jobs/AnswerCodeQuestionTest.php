<?php

use App\Enums\AiTaskStatus;
use App\Enums\ClaudeAuthMode;
use App\Jobs\AnswerCodeQuestion;
use App\Models\CodeQuestion;
use App\Models\GithubAccount;
use App\Services\AppSettings;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    GithubAccount::factory()->create(['login' => 'octocat']);
    app(AppSettings::class)->set(AppSettings::CLAUDE_AUTH_MODE, ClaudeAuthMode::CliLogin->value);
    app(AppSettings::class)->set(AppSettings::CLAUDE_BINARY_PATH, PHP_BINARY);
});

it('stores Claude\'s answer and suggested rewrite', function () {
    $question = CodeQuestion::factory()->queued()->create();
    Process::fake(['*' => Process::result(json_encode([
        'type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => '',
        'structured_output' => ['answer' => 'Use `sum()`.', 'suggested_code' => '$total = collect($items)->sum(\'price\');'],
        'total_cost_usd' => 0.01, 'duration_ms' => 9000,
    ]))]);

    AnswerCodeQuestion::dispatchSync($question);

    expect($question->fresh())
        ->status->toBe(AiTaskStatus::Completed)
        ->answer->toBe('Use `sum()`.')
        ->suggested_code->toBe('$total = collect($items)->sum(\'price\');')
        ->duration_ms->toBe(9000);
    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->input, $question->selected_code)
        && str_contains($process->input, 'Their question: Is there a better way to write this?'));
});

it('includes the earlier questions and answers when asking a follow-up', function () {
    $root = CodeQuestion::factory()->create(['prompt' => 'What does this do?', 'answer' => 'It sums prices.']);
    $followUp = CodeQuestion::factory()->queued()->create(['pull_request_id' => $root->pull_request_id, 'parent_id' => $root->id, 'prompt' => 'Is it slow?']);
    Process::fake(['*' => Process::result(json_encode([
        'type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => '',
        'structured_output' => ['answer' => 'No.', 'suggested_code' => null],
    ]))]);

    AnswerCodeQuestion::dispatchSync($followUp);

    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->input, "Q: What does this do?\nA: It sums prices.")
        && str_contains($process->input, 'Their question: Is it slow?'));
});
