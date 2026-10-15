<?php

use App\Enums\AiTaskStatus;
use App\Enums\ClaudeAuthMode;
use App\Jobs\AnswerCodeQuestion;
use App\Models\CodeQuestion;
use App\Models\PullRequest;
use App\Services\AppSettings;
use Illuminate\Support\Facades\Queue;

/**
 * @return array<string, mixed>
 */
function codeQuestionPayload(array $overrides = []): array
{
    return [
        'path' => 'app/Services/Billing.php',
        'side' => 'RIGHT',
        'start_line' => 20,
        'end_line' => 24,
        'selected_code' => "+    foreach (\$items as \$item) {\n+        \$total += \$item->price;\n+    }",
        'prompt' => 'Is there a better way to write this?',
        ...$overrides,
    ];
}

it('saves a question about highlighted lines and asks Claude in the background', function () {
    Queue::fake([AnswerCodeQuestion::class]);
    onboardedAccount();
    app(AppSettings::class)->set(AppSettings::CLAUDE_AUTH_MODE, ClaudeAuthMode::CliLogin->value);
    $pullRequest = PullRequest::factory()->create();

    $response = $this->post(route('pull-requests.code-questions.store', $pullRequest), codeQuestionPayload());

    $response->assertSessionHas('success', 'Claude is looking at app/Services/Billing.php:20-24.');
    $question = CodeQuestion::sole();
    expect($question)
        ->status->toBe(AiTaskStatus::Queued)
        ->head_sha->toBe($pullRequest->head_sha)
        ->prompt->toBe('Is there a better way to write this?');
    Queue::assertPushedOn('reviews', AnswerCodeQuestion::class, fn (AnswerCodeQuestion $job): bool => $job->question->is($question));
});

it('attaches follow-up questions to the first question of the thread', function () {
    Queue::fake([AnswerCodeQuestion::class]);
    onboardedAccount();
    app(AppSettings::class)->set(AppSettings::CLAUDE_AUTH_MODE, ClaudeAuthMode::CliLogin->value);
    $root = CodeQuestion::factory()->create();
    $firstFollowUp = CodeQuestion::factory()->create(['pull_request_id' => $root->pull_request_id, 'parent_id' => $root->id]);

    $this->post(route('pull-requests.code-questions.store', $root->pull_request_id), codeQuestionPayload(['parent_id' => $firstFollowUp->id, 'prompt' => 'Would that be slower?']));

    expect(CodeQuestion::latest('id')->first()->parent_id)->toBe($root->id);
});

it('rejects a range that ends before it starts', function () {
    Queue::fake([AnswerCodeQuestion::class]);
    onboardedAccount();
    app(AppSettings::class)->set(AppSettings::CLAUDE_AUTH_MODE, ClaudeAuthMode::CliLogin->value);
    $pullRequest = PullRequest::factory()->create();

    $response = $this->post(route('pull-requests.code-questions.store', $pullRequest), codeQuestionPayload(['start_line' => 30, 'end_line' => 10]));

    $response->assertSessionHasErrors('end_line');
    expect(CodeQuestion::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('asks the user to connect Claude before asking about code', function () {
    Queue::fake([AnswerCodeQuestion::class]);
    onboardedAccount();
    $pullRequest = PullRequest::factory()->create();

    $response = $this->post(route('pull-requests.code-questions.store', $pullRequest), codeQuestionPayload());

    $response->assertSessionHas('error', 'Connect Claude in Settings → Claude to ask about code.');
    expect(CodeQuestion::count())->toBe(0);
});

it('deletes a question thread with its follow-ups', function () {
    onboardedAccount();
    $root = CodeQuestion::factory()->create();
    $followUp = CodeQuestion::factory()->create(['pull_request_id' => $root->pull_request_id, 'parent_id' => $root->id]);

    $this->delete(route('code-questions.destroy', $root));

    $this->assertModelMissing($root);
    $this->assertModelMissing($followUp);
});
