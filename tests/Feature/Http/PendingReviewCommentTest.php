<?php

use App\Models\PendingReviewComment;
use App\Models\PullRequest;
use App\Services\AppSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    onboardedAccount();
});

it('keeps a comment on lines in the app until the review is submitted', function () {
    app(AppSettings::class)->set(AppSettings::ALLOW_REPLYING_TO_COMMENTS, true);
    $pullRequest = PullRequest::factory()->create();

    $response = $this->post(route('pull-requests.pending-comments.store', $pullRequest), [
        'body' => 'Extract this into a method.',
        'path' => 'app/Foo.php',
        'side' => 'RIGHT',
        'start_line' => 10,
        'line' => 12,
    ]);

    $response->assertSessionHas('success');
    expect($pullRequest->pendingReviewComments()->sole())
        ->body->toBe('Extract this into a method.')
        ->start_line->toBe(10)
        ->line->toBe(12);
    Http::assertNothingSent();
});

it('does not keep comments while commenting is turned off', function () {
    $pullRequest = PullRequest::factory()->create();

    $response = $this->post(route('pull-requests.pending-comments.store', $pullRequest), [
        'body' => 'Extract this into a method.',
        'path' => 'app/Foo.php',
        'side' => 'RIGHT',
        'line' => 12,
    ]);

    $response->assertSessionHas('error', 'Commenting is turned off in Settings → PR review.');
    expect(PendingReviewComment::count())->toBe(0);
});

it('edits and removes a pending comment', function () {
    $comment = PendingReviewComment::factory()->create(['body' => 'First draft']);

    $this->patch(route('pending-comments.update', $comment), ['body' => 'Second draft'])->assertRedirect();
    expect($comment->fresh()->body)->toBe('Second draft');

    $this->delete(route('pending-comments.destroy', $comment))->assertSessionHas('success');
    expect(PendingReviewComment::count())->toBe(0);
});

it('posts pending comments with the review, on the right side and lines, then forgets them', function () {
    app(AppSettings::class)->set(AppSettings::ALLOW_REPLYING_TO_COMMENTS, true);
    $pullRequest = PullRequest::factory()->create();
    PendingReviewComment::factory()->for($pullRequest)->create(['path' => 'app/Foo.php', 'side' => 'LEFT', 'start_line' => 3, 'line' => 5, 'body' => 'Why remove this?']);
    Http::fake([
        "api.github.com/repos/{$pullRequest->repository->full_name}/pulls/{$pullRequest->number}/reviews" => Http::response(['id' => 1, 'html_url' => 'https://github.com/r/1']),
    ]);

    $response = $this->post(route('pull-requests.reviews.store', $pullRequest), ['event' => 'COMMENT']);

    $response->assertSessionHas('success', 'Review comment submitted.');
    Http::assertSent(fn (Request $request): bool => $request['event'] === 'COMMENT'
        && $request['comments'] === [[
            'side' => 'LEFT',
            'path' => 'app/Foo.php',
            'line' => 5,
            'body' => 'Why remove this?',
            'start_line' => 3,
            'start_side' => 'LEFT',
        ]]);
    expect(PendingReviewComment::count())->toBe(0);
});

it('moves pending comments into the review message when GitHub rejects their lines', function () {
    app(AppSettings::class)->set(AppSettings::ALLOW_REPLYING_TO_COMMENTS, true);
    $pullRequest = PullRequest::factory()->create();
    PendingReviewComment::factory()->for($pullRequest)->create(['path' => 'app/Foo.php', 'line' => 99, 'body' => 'Rename this.']);
    Http::fake([
        "api.github.com/repos/{$pullRequest->repository->full_name}/pulls/{$pullRequest->number}/reviews" => Http::sequence()
            ->push(['message' => 'Unprocessable Entity', 'errors' => ['Line could not be resolved']], 422)
            ->push(['id' => 1, 'html_url' => 'https://github.com/r/1']),
    ]);

    $this->post(route('pull-requests.reviews.store', $pullRequest), ['event' => 'COMMENT'])->assertSessionHas('success');

    Http::assertSent(fn (Request $request): bool => ! isset($request['comments']) && str_contains((string) $request['body'], "`app/Foo.php:99`\n\nRename this."));
    expect(PendingReviewComment::count())->toBe(0);
});

it('keeps pending comments when commenting has been turned off since', function () {
    $pullRequest = PullRequest::factory()->create();
    PendingReviewComment::factory()->for($pullRequest)->create();

    $response = $this->post(route('pull-requests.reviews.store', $pullRequest), ['event' => 'COMMENT', 'body' => 'Thanks']);

    $response->assertSessionHas('error');
    expect(PendingReviewComment::count())->toBe(1);
    Http::assertNothingSent();
});
