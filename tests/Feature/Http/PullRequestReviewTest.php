<?php

use App\Enums\ReviewState;
use App\Models\PullRequest;
use App\Models\ReviewFinding;
use App\Services\AppSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('approves a pull request on GitHub and shows it as approved', function () {
    onboardedAccount();
    $pullRequest = PullRequest::factory()->awaitingTeamReview()->create();
    Http::fake([
        "api.github.com/repos/{$pullRequest->repository->full_name}/pulls/{$pullRequest->number}/reviews" => Http::response(['id' => 1, 'html_url' => 'https://github.com/r/1']),
    ]);

    $response = $this->post(route('pull-requests.reviews.store', $pullRequest), ['event' => 'APPROVE', 'body' => 'LGTM']);

    $response->assertSessionHas('success', 'Pull request approved.');
    expect($pullRequest->fresh())
        ->my_review_state->toBe(ReviewState::Approved)
        ->is_review_requested_from_me->toBeFalse()
        ->attention()->value->toBe('approved');
    Http::assertSent(fn (Request $request): bool => $request['event'] === 'APPROVE' && $request['body'] === 'LGTM');
});

it('requires a comment when requesting changes', function () {
    onboardedAccount();
    $pullRequest = PullRequest::factory()->create();

    $response = $this->post(route('pull-requests.reviews.store', $pullRequest), ['event' => 'REQUEST_CHANGES']);

    $response->assertSessionHasErrors('body');
    Http::assertNothingSent();
});

it('does not let users approve their own pull request', function () {
    onboardedAccount();
    $pullRequest = PullRequest::factory()->authoredByMe()->create();

    $response = $this->post(route('pull-requests.reviews.store', $pullRequest), ['event' => 'APPROVE']);

    $response->assertSessionHas('error', 'GitHub does not let you approve or request changes on your own pull request.');
    Http::assertNothingSent();
});

it('attaches selected findings as inline review comments', function () {
    onboardedAccount();
    app(AppSettings::class)->set(AppSettings::ALLOW_POSTING_FINDINGS, true);
    $finding = ReviewFinding::factory()->create(['path' => 'app/Foo.php', 'line' => 5, 'title' => 'Off by one']);
    $pullRequest = $finding->pullRequest;
    Http::fake([
        "api.github.com/repos/{$pullRequest->repository->full_name}/pulls/{$pullRequest->number}/reviews" => Http::response(['id' => 1, 'html_url' => 'https://github.com/r/1']),
    ]);

    $this->post(route('pull-requests.reviews.store', $pullRequest), [
        'event' => 'REQUEST_CHANGES',
        'body' => 'A couple of things',
        'finding_ids' => [$finding->id],
    ]);

    Http::assertSent(fn (Request $request): bool => $request['event'] === 'REQUEST_CHANGES'
        && $request['comments'][0]['path'] === 'app/Foo.php'
        && $request['comments'][0]['line'] === 5
        && str_contains($request['comments'][0]['body'], 'Off by one'));
    expect($finding->fresh()->posted_at)->not->toBeNull();
});

it('refuses to attach findings while posting findings is turned off', function () {
    onboardedAccount();
    $finding = ReviewFinding::factory()->create();

    $response = $this->post(route('pull-requests.reviews.store', $finding->pull_request_id), [
        'event' => 'COMMENT',
        'finding_ids' => [$finding->id],
    ]);

    $response->assertSessionHas('error', 'Posting findings is turned off in Settings → PR review.');
    Http::assertNothingSent();
});
