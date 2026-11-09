<?php

use App\Models\PullRequest;
use App\Services\AppSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('resolves the conversation on GitHub', function () {
    onboardedAccount(['login' => 'octocat', 'token' => 'ghp_test']);
    app(AppSettings::class)->set(AppSettings::ALLOW_REPLYING_TO_COMMENTS, true);
    $pullRequest = PullRequest::factory()->create();
    Http::fake([
        'api.github.com/graphql' => Http::response(['data' => ['resolveReviewThread' => ['thread' => ['id' => 'PRRT_1', 'isResolved' => true]]]]),
    ]);

    $response = $this->post(route('pull-requests.threads.resolve', $pullRequest), ['thread_id' => 'PRRT_1']);

    $response->assertSessionHas('success', 'Conversation resolved.');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer ghp_test')
        && str_contains($request['query'], 'resolveReviewThread(input: {threadId: $threadId})')
        && $request['variables'] === ['threadId' => 'PRRT_1']);
});

it('refuses to resolve a conversation while replying is turned off', function () {
    onboardedAccount();
    $pullRequest = PullRequest::factory()->create();

    $response = $this->post(route('pull-requests.threads.resolve', $pullRequest), ['thread_id' => 'PRRT_1']);

    $response->assertSessionHas('error', 'Replying to comments is turned off in Settings → PR review.');
    Http::assertNothingSent();
});

it('requires the conversation to resolve', function () {
    onboardedAccount();
    app(AppSettings::class)->set(AppSettings::ALLOW_REPLYING_TO_COMMENTS, true);
    $pullRequest = PullRequest::factory()->create();

    $response = $this->post(route('pull-requests.threads.resolve', $pullRequest), ['thread_id' => '']);

    $response->assertSessionHasErrors('thread_id');
    Http::assertNothingSent();
});

it('shows why GitHub refused to resolve the conversation', function () {
    onboardedAccount();
    app(AppSettings::class)->set(AppSettings::ALLOW_REPLYING_TO_COMMENTS, true);
    $pullRequest = PullRequest::factory()->create();
    Http::fake([
        'api.github.com/graphql' => Http::response([
            'data' => ['resolveReviewThread' => null],
            'errors' => [['type' => 'FORBIDDEN', 'message' => 'Resource not accessible by personal access token']],
        ]),
    ]);

    $response = $this->post(route('pull-requests.threads.resolve', $pullRequest), ['thread_id' => 'PRRT_1']);

    $response->assertSessionHas('error', 'Resource not accessible by personal access token');
    $response->assertSessionMissing('success');
});

it('shows the error GitHub returned when the request fails', function () {
    onboardedAccount();
    app(AppSettings::class)->set(AppSettings::ALLOW_REPLYING_TO_COMMENTS, true);
    $pullRequest = PullRequest::factory()->create();
    Http::fake(['api.github.com/graphql' => Http::response(['message' => 'Bad credentials'], 401)]);

    $response = $this->post(route('pull-requests.threads.resolve', $pullRequest), ['thread_id' => 'PRRT_1']);

    $response->assertSessionHas('error', 'GitHub returned 401: Bad credentials');
    $response->assertSessionMissing('success');
});

it('tells the user when GitHub cannot be reached', function () {
    Sleep::fake();
    onboardedAccount();
    app(AppSettings::class)->set(AppSettings::ALLOW_REPLYING_TO_COMMENTS, true);
    $pullRequest = PullRequest::factory()->create();
    Http::fake(['api.github.com/graphql' => fn () => throw new ConnectionException('cURL error 6: Could not resolve host: api.github.com')]);

    $response = $this->post(route('pull-requests.threads.resolve', $pullRequest), ['thread_id' => 'PRRT_1']);

    $response->assertSessionHas('error', 'Could not reach GitHub. Check your internet connection.');
    $response->assertSessionMissing('success');
});
