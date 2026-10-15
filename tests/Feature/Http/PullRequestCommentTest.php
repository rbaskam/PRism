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

it('refuses to reply while replying is turned off', function () {
    onboardedAccount();
    $pullRequest = PullRequest::factory()->create();

    $response = $this->post(route('pull-requests.comments.store', $pullRequest), ['body' => 'Hi']);

    $response->assertSessionHas('error', 'Replying to comments is turned off in Settings → PR review.');
    Http::assertNothingSent();
});
