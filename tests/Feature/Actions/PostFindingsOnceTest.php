<?php

use App\Actions\PostFindingToPullRequest;
use App\Actions\SubmitPullRequestReview;
use App\Enums\SubmitReviewEvent;
use App\Models\ReviewFinding;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    onboardedAccount();
});

it('leaves out findings already on the pull request when submitting a review', function () {
    $posted = ReviewFinding::factory()->create(['path' => 'app/Foo.php', 'line' => 5, 'posted_at' => now()->subMinute()]);
    $notPosted = ReviewFinding::factory()->for($posted->pullRequest)->create(['path' => 'app/Bar.php', 'line' => 7]);
    $pullRequest = $posted->pullRequest;
    Http::fake([
        "api.github.com/repos/{$pullRequest->repository->full_name}/pulls/{$pullRequest->number}/reviews" => Http::response(['id' => 1, 'html_url' => 'https://github.com/r/1']),
    ]);

    app(SubmitPullRequestReview::class)->handle($pullRequest, SubmitReviewEvent::Comment, null, new Collection([$posted, $notPosted]));

    Http::assertSent(fn (Request $request): bool => count($request['comments']) === 1 && $request['comments'][0]['path'] === 'app/Bar.php');
    expect($notPosted->fresh()->posted_at)->not->toBeNull();
});

it('does not post a finding that is already on the pull request again', function () {
    $finding = ReviewFinding::factory()->create(['path' => 'app/Foo.php', 'line' => 5, 'posted_at' => now()->subMinute()]);

    app(PostFindingToPullRequest::class)->handle($finding, 'Posted again');

    Http::assertNothingSent();
});
