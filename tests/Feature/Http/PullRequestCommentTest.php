<?php

use App\Models\PullRequest;
use App\Services\AppSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('replies in a review thread', function () {
    onboardedAccount();
    app(AppSettings::class)->set(AppSettings::ALLOW_REPLYING_TO_COMMENTS, true);
    $pullRequest = PullRequest::factory()->create();
    Http::fake([
        "api.github.com/repos/{$pullRequest->repository->full_name}/pulls/{$pullRequest->number}/comments/321/replies" => Http::response(['id' => 1], 201),
    ]);

    $response = $this->post(route('pull-requests.comments.store', $pullRequest), ['body' => 'Good catch, fixed.', 'in_reply_to' => 321]);

    $response->assertSessionHas('success', 'Reply posted.');
    Http::assertSent(fn (Request $request): bool => $request['body'] === 'Good catch, fixed.');
});

it('comments on a range of lines in the diff', function () {
    onboardedAccount();
    app(AppSettings::class)->set(AppSettings::ALLOW_REPLYING_TO_COMMENTS, true);
    $pullRequest = PullRequest::factory()->create(['head_sha' => 'abc123']);
    Http::fake([
        "api.github.com/repos/{$pullRequest->repository->full_name}/pulls/{$pullRequest->number}/comments" => Http::response(['id' => 1], 201),
    ]);

    $response = $this->post(route('pull-requests.comments.store', $pullRequest), [
        'body' => 'This loop never ends.',
        'path' => 'app/Foo.php',
        'side' => 'RIGHT',
        'start_line' => 10,
        'line' => 12,
    ]);

    $response->assertSessionHas('success', 'Comment added.');
    Http::assertSent(fn (Request $request): bool => $request->data() === [
        'body' => 'This loop never ends.',
        'commit_id' => 'abc123',
        'path' => 'app/Foo.php',
        'line' => 12,
        'side' => 'RIGHT',
        'start_line' => 10,
        'start_side' => 'RIGHT',
    ]);
});

it('comments on a single removed line', function () {
    onboardedAccount();
    app(AppSettings::class)->set(AppSettings::ALLOW_REPLYING_TO_COMMENTS, true);
    $pullRequest = PullRequest::factory()->create(['head_sha' => 'abc123']);
    Http::fake([
        "api.github.com/repos/{$pullRequest->repository->full_name}/pulls/{$pullRequest->number}/comments" => Http::response(['id' => 1], 201),
    ]);

    $this->post(route('pull-requests.comments.store', $pullRequest), [
        'body' => 'Why was this removed?',
        'path' => 'app/Foo.php',
        'side' => 'LEFT',
        'start_line' => 7,
        'line' => 7,
    ]);

    Http::assertSent(fn (Request $request): bool => $request['side'] === 'LEFT' && $request['line'] === 7 && ! isset($request['start_line']));
});

it('refuses to reply while replying is turned off', function () {
    onboardedAccount();
    $pullRequest = PullRequest::factory()->create();

    $response = $this->post(route('pull-requests.comments.store', $pullRequest), ['body' => 'Hi']);

    $response->assertSessionHas('error', 'Replying to comments is turned off in Settings → PR review.');
    Http::assertNothingSent();
});
