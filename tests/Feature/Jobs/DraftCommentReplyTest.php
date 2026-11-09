<?php

use App\Enums\AiTaskStatus;
use App\Enums\ClaudeAuthMode;
use App\Jobs\DraftCommentReply;
use App\Models\GithubAccount;
use App\Models\PullRequest;
use App\Models\ReplyDraft;
use App\Services\AppSettings;
use App\Services\Claude\ClaudeException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    GithubAccount::factory()->create(['login' => 'octocat', 'token' => 'ghp_test']);
    app(AppSettings::class)->set(AppSettings::CLAUDE_AUTH_MODE, ClaudeAuthMode::CliLogin->value);
    app(AppSettings::class)->set(AppSettings::CLAUDE_BINARY_PATH, PHP_BINARY);
});

it('saves the reply Claude drafts for the user to edit and send', function () {
    $draft = ReplyDraft::factory()->create(['status' => AiTaskStatus::Queued, 'body' => null]);
    Process::fake(['*' => Process::result(json_encode([
        'type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => '',
        'structured_output' => ['reply' => "\n  Good catch, fixed in the next commit.  \n"],
    ]))]);

    DraftCommentReply::dispatchSync($draft, [['author' => 'reviewer', 'body' => 'This query runs once per row.']]);

    expect($draft->fresh())
        ->status->toBe(AiTaskStatus::Completed)
        ->body->toBe('Good catch, fixed in the next commit.')
        ->error->toBeNull();
});

it('gives Claude the discussion, the code it is about and how the user wants to reply', function () {
    $pullRequest = PullRequest::factory()
        ->for(trackedRepository('acme/api', ['local_path' => sys_get_temp_dir()]))
        ->create(['number' => 42]);
    $draft = ReplyDraft::factory()->for($pullRequest)->create([
        'status' => AiTaskStatus::Queued,
        'body' => null,
        'instructions' => 'Agree and say it is fixed in the next commit.',
    ]);
    Process::fake(['*' => Process::result(json_encode([
        'type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => '',
        'structured_output' => ['reply' => 'Fixed, thanks!'],
    ]))]);

    DraftCommentReply::dispatchSync($draft, [
        ['author' => 'reviewer', 'body' => 'This query runs once per row.'],
        ['author' => 'octocat', 'body' => 'Which one?'],
        ['author' => 'reviewer', 'body' => 'The one in the loop.'],
    ], 'app/Services/Billing.php', "@@ -10,2 +10,3 @@\n+foreach (\$rows as \$row) {");

    Process::assertRan(fn (PendingProcess $process): bool => $process->path === sys_get_temp_dir()
        && $process->environment['GH_TOKEN'] === 'ghp_test'
        && str_contains($process->input, 'Draft a reply from @octocat to the latest comment in this discussion on pull request acme/api#42.')
        && str_contains($process->input, "The discussion is about `app/Services/Billing.php`:\n```diff\n@@ -10,2 +10,3 @@\n+foreach (\$rows as \$row) {\n```")
        && str_contains($process->input, "@reviewer: This query runs once per row.\n@octocat: Which one?\n@reviewer: The one in the loop.")
        && str_contains($process->input, 'How @octocat wants to reply: Agree and say it is fixed in the next commit.'));
});

it('drafts a reply to a conversation comment without a file or instructions', function () {
    $draft = ReplyDraft::factory()->create(['status' => AiTaskStatus::Queued, 'body' => null, 'instructions' => null]);
    Process::fake(['*' => Process::result(json_encode([
        'type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => '',
        'structured_output' => ['reply' => 'Thanks for the review!'],
    ]))]);

    DraftCommentReply::dispatchSync($draft, [['author' => 'reviewer', 'body' => 'Looks good overall.']]);

    Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->input, '@reviewer: Looks good overall.')
        && ! str_contains($process->input, 'The discussion is about')
        && ! str_contains($process->input, 'wants to reply'));
});

it('marks the draft failed with the reason when Claude Code errors', function () {
    $draft = ReplyDraft::factory()->create(['status' => AiTaskStatus::Queued, 'body' => null]);
    Process::fake(['*' => Process::result(json_encode([
        'type' => 'result', 'subtype' => 'error_max_budget_usd', 'is_error' => true, 'result' => 'Budget exceeded',
    ]))]);

    expect(fn () => DraftCommentReply::dispatchSync($draft, [['author' => 'reviewer', 'body' => 'Why?']]))
        ->toThrow(ClaudeException::class);

    expect($draft->fresh())
        ->status->toBe(AiTaskStatus::Failed)
        ->error->toBe('Claude Code stopped with an error: Budget exceeded')
        ->body->toBeNull();
});

it('marks the draft failed when Claude returns no reply', function () {
    $draft = ReplyDraft::factory()->create(['status' => AiTaskStatus::Queued, 'body' => null]);
    Process::fake(['*' => Process::result(json_encode([
        'type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => 'I could not find the comment.',
    ]))]);

    expect(fn () => DraftCommentReply::dispatchSync($draft, [['author' => 'reviewer', 'body' => 'Why?']]))
        ->toThrow(ClaudeException::class, 'Claude did not return a reply.');

    expect($draft->fresh())
        ->status->toBe(AiTaskStatus::Failed)
        ->error->toBe('Claude did not return a reply.')
        ->body->toBeNull();
});

it('explains a draft that stopped without a reason', function () {
    $draft = ReplyDraft::factory()->create(['status' => AiTaskStatus::Running, 'body' => null]);

    (new DraftCommentReply($draft, []))->failed(null);

    expect($draft->fresh())
        ->status->toBe(AiTaskStatus::Failed)
        ->error->toBe('Drafting the reply stopped unexpectedly.');
});
