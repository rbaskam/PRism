<?php

use App\Enums\ClaudeAuthMode;
use App\Jobs\RunPullRequestReview;
use App\Models\PullRequest;
use App\Models\ReviewRun;
use App\Services\AppSettings;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

it('saves a review prompt for one repository', function () {
    onboardedAccount();
    $repository = trackedRepository('acme/api');

    $response = $this->patch(route('repositories.update', $repository), ['review_prompt' => 'Only check {repository}#{number} for SQL injection.']);

    $response->assertSessionHas('success', 'Saved the review prompt for acme/api.');
    expect($repository->fresh()->review_prompt)->toBe('Only check {repository}#{number} for SQL injection.');
});

it('rejects a review prompt that is too long or not text', function (mixed $prompt) {
    onboardedAccount();
    $repository = trackedRepository('acme/api', ['review_prompt' => 'Check for typos.']);

    $response = $this->patch(route('repositories.update', $repository), ['review_prompt' => $prompt]);

    $response->assertSessionHasErrors('review_prompt');
    expect($repository->fresh()->review_prompt)->toBe('Check for typos.');
})->with([
    'too long' => fn () => str_repeat('a', AppSettings::REVIEW_PROMPT_MAX_LENGTH + 1),
    'not text' => [['Check', 'for', 'typos']],
]);

it('goes back to the app-wide prompt when the repository prompt is cleared', function () {
    onboardedAccount();
    $repository = trackedRepository('acme/api', ['review_prompt' => 'Check for typos.']);

    $response = $this->patch(route('repositories.update', $repository), ['review_prompt' => null]);

    $response->assertSessionHas('success', 'acme/api now uses the app-wide review prompt.');
    expect($repository->fresh()->review_prompt)->toBeNull();
});

it('does not keep a copy of the app-wide prompt as the repository prompt', function () {
    onboardedAccount();
    app(AppSettings::class)->set(AppSettings::REVIEW_PROMPT, 'Review {repository}#{number} carefully.');
    $repository = trackedRepository('acme/api');

    $this->patch(route('repositories.update', $repository), ['review_prompt' => 'Review {repository}#{number} carefully.']);

    expect($repository->fresh()->review_prompt)->toBeNull();
});

it('leaves the review prompt alone when the local checkout or tracking changes', function () {
    onboardedAccount();
    $repository = trackedRepository('acme/api', ['review_prompt' => 'Check for typos.']);

    $response = $this->patch(route('repositories.update', $repository), ['is_tracked' => false]);

    $response->assertSessionHas('success', 'Updated acme/api.');
    expect($repository->fresh())
        ->is_tracked->toBeFalse()
        ->review_prompt->toBe('Check for typos.');
});

it('reviews pull requests with their repository prompt, and others with the app-wide prompt', function () {
    Queue::fake([RunPullRequestReview::class]);
    onboardedAccount();
    app(AppSettings::class)->set(AppSettings::CLAUDE_AUTH_MODE, ClaudeAuthMode::CliLogin->value);
    app(AppSettings::class)->set(AppSettings::REVIEW_PROMPT, 'Review {repository}#{number} please.');
    $api = PullRequest::factory()->for(trackedRepository('acme/api', ['review_prompt' => 'Only check {repository}#{number} for SQL injection.']))->create(['number' => 7]);
    $web = PullRequest::factory()->for(trackedRepository('acme/web'))->create(['number' => 8]);

    $this->post(route('pull-requests.review-runs.store', $api));
    $this->post(route('pull-requests.review-runs.store', $web));

    expect(ReviewRun::query()->where('pull_request_id', $api->id)->sole()->prompt)->toBe('Only check acme/api#7 for SQL injection.')
        ->and(ReviewRun::query()->where('pull_request_id', $web->id)->sole()->prompt)->toBe('Review acme/web#8 please.');
});

it('shows the prompt a review will use on the pull request page', function () {
    onboardedAccount();
    $pullRequest = PullRequest::factory()->for(trackedRepository('acme/api', ['review_prompt' => 'Only check {repository}#{number}.']))->create(['number' => 7]);

    $response = $this->get(route('pull-requests.show', $pullRequest));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('reviewPrompt', 'Only check acme/api#7.')
        ->where('pullRequest.repository.has_custom_review_prompt', true));
});

it('lists the repositories with their own prompt in the review settings', function () {
    onboardedAccount();
    $api = trackedRepository('acme/api', ['review_prompt' => 'Check for typos.']);
    trackedRepository('acme/web');

    $response = $this->get(route('settings.review'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('repositoriesWithOwnPrompt', [['id' => $api->id, 'full_name' => 'acme/api']]));
});
