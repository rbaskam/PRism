<?php

use App\Enums\AiTaskStatus;
use App\Enums\ClaudeAuthMode;
use App\Enums\CommentKind;
use App\Jobs\DraftCommentReply;
use App\Models\PullRequest;
use App\Models\ReplyDraft;
use App\Services\AppSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A pull request whose saved conversation has a review thread comment PRRC_1 and a conversation comment IC_1.
 */
function pullRequestWithComments(): PullRequest
{
    return PullRequest::factory()->create([
        'conversation' => [
            'threads' => [['id' => 'PRRT_1', 'comments' => [['node_id' => 'PRRC_1', 'database_id' => 321]]]],
            'comments' => [['node_id' => 'IC_1', 'database_id' => 654]],
            'reviews' => [],
        ],
        'conversation_synced_at' => now(),
    ]);
}

it('saves a queued draft and asks Claude to write the reply in the background', function () {
    Queue::fake([DraftCommentReply::class]);
    onboardedAccount();
    app(AppSettings::class)->set(AppSettings::ALLOW_REPLYING_TO_COMMENTS, true);
    app(AppSettings::class)->set(AppSettings::CLAUDE_AUTH_MODE, ClaudeAuthMode::CliLogin->value);
    $pullRequest = pullRequestWithComments();
    $conversation = [
        ['author' => 'reviewer', 'body' => 'Why not cache this?'],
        ['author' => 'octocat', 'body' => 'Which part?'],
    ];

    $response = $this->post(route('pull-requests.reply-drafts.store', $pullRequest), [
        'comment_node_id' => 'PRRC_1',
        'comment_database_id' => 321,
        'comment_type' => 'review_thread',
        'instructions' => 'Explain that it changes on every request.',
        'conversation' => $conversation,
        'path' => 'app/Services/Billing.php',
        'diff_hunk' => '@@ -1 +1 @@',
    ]);

    $response->assertSessionHas('success', 'Claude is drafting a reply.');
    $draft = ReplyDraft::sole();
    expect($draft)
        ->pull_request_id->toBe($pullRequest->id)
        ->comment_node_id->toBe('PRRC_1')
        ->comment_database_id->toBe(321)
        ->comment_type->toBe(CommentKind::ReviewThread)
        ->status->toBe(AiTaskStatus::Queued)
        ->instructions->toBe('Explain that it changes on every request.')
        ->body->toBeNull();
    Queue::assertPushedOn('reviews', DraftCommentReply::class, fn (DraftCommentReply $job): bool => $job->draft->is($draft)
        && $job->conversation === $conversation
        && $job->path === 'app/Services/Billing.php'
        && $job->diffHunk === '@@ -1 +1 @@');
});

it('drafts a reply to a conversation comment that has no file or line', function () {
    Queue::fake([DraftCommentReply::class]);
    onboardedAccount();
    app(AppSettings::class)->set(AppSettings::ALLOW_REPLYING_TO_COMMENTS, true);
    app(AppSettings::class)->set(AppSettings::CLAUDE_AUTH_MODE, ClaudeAuthMode::CliLogin->value);
    $pullRequest = pullRequestWithComments();

    $this->post(route('pull-requests.reply-drafts.store', $pullRequest), [
        'comment_node_id' => 'IC_1',
        'comment_type' => 'conversation',
        'conversation' => [['author' => 'reviewer', 'body' => 'Can you add a screenshot?']],
    ])->assertSessionHas('success', 'Claude is drafting a reply.');

    expect(ReplyDraft::sole())
        ->comment_type->toBe(CommentKind::Conversation)
        ->comment_database_id->toBeNull()
        ->instructions->toBeNull();
    Queue::assertPushed(DraftCommentReply::class, fn (DraftCommentReply $job): bool => $job->path === null && $job->diffHunk === null);
});

it('refuses to draft a reply to a comment that is not on the pull request', function (string $nodeId, string $type) {
    Queue::fake([DraftCommentReply::class]);
    onboardedAccount();
    app(AppSettings::class)->set(AppSettings::ALLOW_REPLYING_TO_COMMENTS, true);
    app(AppSettings::class)->set(AppSettings::CLAUDE_AUTH_MODE, ClaudeAuthMode::CliLogin->value);
    $pullRequest = pullRequestWithComments();

    $response = $this->post(route('pull-requests.reply-drafts.store', $pullRequest), [
        'comment_node_id' => $nodeId,
        'comment_type' => $type,
        'conversation' => [['author' => 'reviewer', 'body' => 'Why?']],
    ]);

    $response->assertSessionHas('error', "That comment isn't on this pull request any more. Refresh the conversation and try again.");
    expect(ReplyDraft::count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'a comment on another pull request' => ['PRRC_other', 'review_thread'],
    'a conversation comment sent as a thread comment' => ['IC_1', 'review_thread'],
    'a thread comment sent as a conversation comment' => ['PRRC_1', 'conversation'],
]);

it('refuses to draft a reply while replying is turned off', function () {
    Queue::fake([DraftCommentReply::class]);
    onboardedAccount();
    app(AppSettings::class)->set(AppSettings::CLAUDE_AUTH_MODE, ClaudeAuthMode::CliLogin->value);
    $pullRequest = PullRequest::factory()->create();

    $response = $this->post(route('pull-requests.reply-drafts.store', $pullRequest), [
        'comment_node_id' => 'PRRC_1',
        'comment_type' => 'review_thread',
        'conversation' => [['author' => 'reviewer', 'body' => 'Why?']],
    ]);

    $response->assertSessionHas('error', 'Replying to comments is turned off in Settings → PR review.');
    expect(ReplyDraft::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('asks the user to connect Claude before drafting a reply', function () {
    Queue::fake([DraftCommentReply::class]);
    onboardedAccount();
    app(AppSettings::class)->set(AppSettings::ALLOW_REPLYING_TO_COMMENTS, true);
    $pullRequest = PullRequest::factory()->create();

    $response = $this->post(route('pull-requests.reply-drafts.store', $pullRequest), [
        'comment_node_id' => 'PRRC_1',
        'comment_type' => 'review_thread',
        'conversation' => [['author' => 'reviewer', 'body' => 'Why?']],
    ]);

    $response->assertSessionHas('error', 'Connect Claude in Settings → Claude to draft replies.');
    expect(ReplyDraft::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects a reply request', function (array $overrides, string $field) {
    Queue::fake([DraftCommentReply::class]);
    onboardedAccount();
    app(AppSettings::class)->set(AppSettings::ALLOW_REPLYING_TO_COMMENTS, true);
    app(AppSettings::class)->set(AppSettings::CLAUDE_AUTH_MODE, ClaudeAuthMode::CliLogin->value);
    $pullRequest = pullRequestWithComments();

    $response = $this->post(route('pull-requests.reply-drafts.store', $pullRequest), [
        'comment_node_id' => 'PRRC_1',
        'comment_type' => 'review_thread',
        'conversation' => [['author' => 'reviewer', 'body' => 'Why?']],
        ...$overrides,
    ]);

    $response->assertSessionHasErrors($field);
    expect(ReplyDraft::count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'without the comment to reply to' => [['comment_node_id' => ''], 'comment_node_id'],
    'for an unknown kind of comment' => [['comment_type' => 'commit_comment'], 'comment_type'],
    'without the discussion' => [['conversation' => []], 'conversation'],
    'with a comment that has no body' => [['conversation' => [['author' => 'reviewer']]], 'conversation.0.body'],
]);

it('shows the pull request only its own reply drafts, newest first', function () {
    Http::preventStrayRequests();
    onboardedAccount();
    $pullRequest = PullRequest::factory()->create();
    $failed = ReplyDraft::factory()->for($pullRequest)->create([
        'comment_node_id' => 'PRRC_1',
        'status' => AiTaskStatus::Failed,
        'instructions' => 'Say no politely.',
        'body' => null,
        'error' => 'Claude did not return a reply.',
    ]);
    $drafting = ReplyDraft::factory()->for($pullRequest)->create(['comment_node_id' => 'PRRC_2', 'status' => AiTaskStatus::Running, 'body' => null]);
    ReplyDraft::factory()->create(['comment_node_id' => 'PRRC_other']);

    $response = $this->get(route('pull-requests.show', $pullRequest));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('replyDrafts', 2)
        ->where('replyDrafts.0.id', $drafting->id)
        ->where('replyDrafts.0.is_active', true)
        ->has('replyDrafts.1', fn (Assert $draft) => $draft
            ->where('id', $failed->id)
            ->where('comment_node_id', 'PRRC_1')
            ->where('status', 'failed')
            ->where('is_active', false)
            ->where('instructions', 'Say no politely.')
            ->where('body', null)
            ->where('error', 'Claude did not return a reply.')
            ->where('created_at', $failed->created_at->toIso8601String())));
});
