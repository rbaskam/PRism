<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\PullRequest;
use Inertia\Testing\AssertableInertia as Assert;

it('keeps a flash message for the page when a background poll arrives first', function () {
    onboardedAccount();
    $pullRequest = PullRequest::factory()->create();
    $this->post(route('pull-requests.review-runs.store', $pullRequest));
    $this->get(route('pull-requests.show', $pullRequest), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'pull-requests/Show',
        'X-Inertia-Partial-Data' => 'counts',
    ]);

    $response = $this->get(route('pull-requests.show', $pullRequest));

    $response->assertInertia(fn (Assert $page) => $page->where('flash.error', 'Connect Claude in Settings → Claude to run AI reviews.'));
});
