<?php

use App\Enums\AiTaskStatus;
use App\Enums\AlertType;
use App\Enums\ClaudeAuthMode;
use App\Jobs\RunPullRequestReview;
use App\Models\Alert;
use App\Models\GithubAccount;
use App\Models\ReviewFinding;
use App\Models\ReviewRun;
use App\Services\AppSettings;
use App\Services\Claude\ClaudeCode;
use App\Services\Claude\ClaudeException;
use Illuminate\Process\PendingProcess;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

/**
 * @param  array<string, mixed>  $structuredOutput
 */
function claudeOutput(array $structuredOutput): string
{
    return json_encode([
        'type' => 'result',
        'subtype' => 'success',
        'is_error' => false,
        'result' => json_encode($structuredOutput),
        'structured_output' => $structuredOutput,
        'total_cost_usd' => 0.4321,
        'duration_ms' => 95000,
        'session_id' => 'session-123',
    ]);
}

function connectClaude(ClaudeAuthMode $mode = ClaudeAuthMode::CliLogin, ?string $credential = null): void
{
    $settings = app(AppSettings::class);
    $settings->set(AppSettings::CLAUDE_AUTH_MODE, $mode->value);
    $settings->set(AppSettings::CLAUDE_BINARY_PATH, PHP_BINARY);
    $settings->setSecret(AppSettings::CLAUDE_CREDENTIAL, $credential);
}

it('runs Claude Code read-only and records the findings', function () {
    GithubAccount::factory()->create(['token' => 'gho_secret']);
    connectClaude();
    $run = ReviewRun::factory()->queued()->create(['prompt' => 'Review PR #7']);
    Process::fake(['*' => Process::result(claudeOutput([
        'summary' => 'One bug.',
        'verdict' => 'request_changes',
        'findings' => [['category' => 'bug', 'severity' => 'high', 'path' => 'app/Foo.php', 'line' => 3, 'title' => 'Off by one', 'body' => 'Loop skips the last item.', 'suggestion' => null]],
        'previous_findings' => [],
    ]))]);

    RunPullRequestReview::dispatchSync($run);

    expect($run->fresh())
        ->status->toBe(AiTaskStatus::Completed)
        ->cost_usd->toBe(0.4321)
        ->duration_ms->toBe(95000)
        ->new_findings_count->toBe(1);
    expect(ReviewFinding::sole()->title)->toBe('Off by one');
    expect(Alert::sole())
        ->type->toBe(AlertType::ReviewFinished)
        ->url->toBe("/pull-requests/{$run->pull_request_id}?tab=findings");
    Process::assertRan(function (PendingProcess $process): bool {
        $command = $process->command;

        return $process->input === 'Review PR #7'
            && $process->environment['GH_TOKEN'] === 'gho_secret'
            && $command[array_search('--permission-mode', $command) + 1] === 'dontAsk'
            && $command[array_search('--tools', $command) + 1] === 'Bash,Read,Grep,Glob';
    });
});

it('passes the saved API key to Claude Code', function () {
    GithubAccount::factory()->create();
    connectClaude(ClaudeAuthMode::ApiKey, 'sk-ant-test');
    $run = ReviewRun::factory()->queued()->create();
    Process::fake(['*' => Process::result(claudeOutput(['summary' => 's', 'verdict' => 'approve', 'findings' => [], 'previous_findings' => []]))]);

    RunPullRequestReview::dispatchSync($run);

    Process::assertRan(fn (PendingProcess $process): bool => $process->environment['ANTHROPIC_API_KEY'] === 'sk-ant-test');
});

it('marks the run failed with the reason when Claude Code errors', function () {
    GithubAccount::factory()->create();
    connectClaude();
    $run = ReviewRun::factory()->queued()->create();
    Process::fake(['*' => Process::result(json_encode([
        'type' => 'result',
        'subtype' => 'error_max_budget_usd',
        'is_error' => true,
        'result' => 'Budget exceeded',
    ]))]);

    expect(fn () => RunPullRequestReview::dispatchSync($run))->toThrow(ClaudeException::class);

    expect($run->fresh())
        ->status->toBe(AiTaskStatus::Failed)
        ->error->toContain('Budget exceeded');
});

it('tells Claude about earlier findings and the user decisions on them', function () {
    GithubAccount::factory()->create();
    connectClaude();
    $earlier = ReviewFinding::factory()->create(['title' => 'Race condition in sync', 'status' => 'not_blocker']);
    $run = ReviewRun::factory()->queued()->create(['pull_request_id' => $earlier->pull_request_id]);
    Process::fake(['*' => Process::result(claudeOutput(['summary' => 's', 'verdict' => 'approve', 'findings' => [], 'previous_findings' => []]))]);

    RunPullRequestReview::dispatchSync($run);

    Process::assertRan(function (PendingProcess $process): bool {
        $systemPrompt = $process->command[array_search('--append-system-prompt', $process->command) + 1];

        return str_contains($systemPrompt, 'Race condition in sync') && str_contains($systemPrompt, 'Not a blocker');
    });
});

it('stops Claude as soon as the user cancels the review', function () {
    GithubAccount::factory()->create();
    connectClaude();
    $run = ReviewRun::factory()->queued()->create();
    Process::fake(['*' => Process::describe()
        ->output(claudeOutput(['summary' => 's', 'verdict' => 'approve', 'findings' => [['category' => 'bug', 'severity' => 'high', 'path' => 'a.php', 'line' => 1, 'title' => 'Bug', 'body' => 'b', 'suggestion' => null]], 'previous_findings' => []]))
        ->iterations(ClaudeCode::POLLS_PER_CHECK * 3)]);
    Sleep::fake();
    Sleep::whenFakingSleep(fn () => ReviewRun::findOrFail($run->id)->cancel());

    RunPullRequestReview::dispatchSync($run);

    Sleep::assertSleptTimes(ClaudeCode::POLLS_PER_CHECK);
    expect($run->fresh()->status)->toBe(AiTaskStatus::Cancelled);
    expect(ReviewFinding::count())->toBe(0);
    expect(Alert::count())->toBe(0);
});

it('does not start Claude for a review cancelled while it waited for the worker', function () {
    connectClaude();
    $run = ReviewRun::factory()->queued()->create();
    $run->cancel();
    Process::fake();

    RunPullRequestReview::dispatchSync($run);

    Process::assertNothingRan();
    expect($run->fresh()->status)->toBe(AiTaskStatus::Cancelled);
});

it('touches the run every so often while Claude works, to show it is still going', function () {
    GithubAccount::factory()->create();
    connectClaude();
    $this->travelTo($startedAt = now()->startOfSecond()->toImmutable());
    $run = ReviewRun::factory()->queued()->create();
    Process::fake(['*' => Process::describe()
        ->output(claudeOutput(['summary' => 's', 'verdict' => 'approve', 'findings' => [], 'previous_findings' => []]))
        ->iterations(ReviewRun::HEARTBEAT_SECONDS * ClaudeCode::POLLS_PER_CHECK * 3)]);
    Sleep::fake(syncWithCarbon: true);
    $heartbeats = [];
    Sleep::whenFakingSleep(function () use ($run, &$heartbeats): void {
        $heartbeats[] = ReviewRun::findOrFail($run->id)->updated_at->toIso8601String();
    });

    RunPullRequestReview::dispatchSync($run);

    expect(array_values(array_unique($heartbeats)))->toBe([
        $startedAt->toIso8601String(),
        $startedAt->addSeconds(ReviewRun::HEARTBEAT_SECONDS)->toIso8601String(),
        $startedAt->addSeconds(ReviewRun::HEARTBEAT_SECONDS * 2)->toIso8601String(),
    ]);
    expect($run->fresh()->status)->toBe(AiTaskStatus::Completed);
});

it('stops Claude when it runs out of time', function () {
    GithubAccount::factory()->create();
    connectClaude();
    $run = ReviewRun::factory()->queued()->create();
    Process::fake(['*' => Process::describe()->iterations(1501 * ClaudeCode::POLLS_PER_CHECK)]);
    Sleep::fake(syncWithCarbon: true);

    expect(fn () => RunPullRequestReview::dispatchSync($run))->toThrow(ClaudeException::class, 'did not finish within 25 minutes');

    expect($run->fresh())
        ->status->toBe(AiTaskStatus::Failed)
        ->error->toContain('25 minutes');
});

it('leaves a cancelled review alone when the queue gives up on its job', function () {
    $run = ReviewRun::factory()->running()->create(['status' => AiTaskStatus::Cancelled]);

    (new RunPullRequestReview($run))->failed(new MaxAttemptsExceededException('Attempted too many times.'));

    expect($run->fresh()->status)->toBe(AiTaskStatus::Cancelled);
    expect(Alert::count())->toBe(0);
});
