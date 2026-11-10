<?php

use App\Models\GithubAccount;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Services\AppSettings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('lists the tracked repositories by name with their pull request counts', function () {
    onboardedAccount();
    $web = trackedRepository('acme/web');
    $api = trackedRepository('acme/api', ['local_path' => '/Users/octocat/Code/api']);
    Repository::factory()->untracked()->create(['full_name' => 'acme/archived']);
    PullRequest::factory()->for($api)->awaitingMyReview()->create();
    PullRequest::factory()->for($api)->closed()->create();

    $response = $this->get(route('repositories.index'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('repositories/Index')
        ->has('repositories', 2)
        ->where('repositories.0.id', $api->id)
        ->where('repositories.0.local_path', '/Users/octocat/Code/api')
        ->where('repositories.0.counts', ['open' => 1, 'awaiting' => 1, 'approved' => 0])
        ->where('repositories.1.id', $web->id)
        ->where('repositories.1.counts', ['open' => 0, 'awaiting' => 0, 'approved' => 0]));
});

it('shows no repositories until some are tracked', function () {
    onboardedAccount();
    Repository::factory()->untracked()->create();

    $response = $this->get(route('repositories.index'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page->where('repositories', []));
});

it('shows a repository with its open and recently closed pull requests, most recently updated first', function () {
    onboardedAccount();
    $repository = trackedRepository('acme/api');
    $older = PullRequest::factory()->for($repository)->create(['github_updated_at' => now()->subDay()]);
    $recentlyClosed = PullRequest::factory()->for($repository)->create([
        'state' => PullRequest::STATE_CLOSED,
        'closed_at' => now()->subDays(PullRequest::RECENTLY_CLOSED_DAYS - 1),
        'github_updated_at' => now()->subHours(2),
    ]);
    $newest = PullRequest::factory()->for($repository)->create(['github_updated_at' => now()]);
    PullRequest::factory()->for($repository)->create([
        'state' => PullRequest::STATE_CLOSED,
        'closed_at' => now()->subDays(PullRequest::RECENTLY_CLOSED_DAYS + 1),
        'github_updated_at' => now(),
    ]);
    PullRequest::factory()->for(trackedRepository('acme/web'))->create();

    $response = $this->get(route('repositories.show', $repository));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('repositories/Show')
        ->where('repository.id', $repository->id)
        ->where('repository.full_name', 'acme/api')
        ->missing('repository.counts')
        ->has('pullRequests', 3)
        ->where('pullRequests.0.id', $newest->id)
        ->where('pullRequests.1.id', $recentlyClosed->id)
        ->where('pullRequests.2.id', $older->id));
});

it('shows a repository with no pull requests', function () {
    onboardedAccount();
    $repository = trackedRepository('acme/api');

    $response = $this->get(route('repositories.show', $repository));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('repository.id', $repository->id)
        ->where('pullRequests', []));
});

it('lists the connected account\'s teams on the repository page', function () {
    app(AppSettings::class)->set(AppSettings::ONBOARDING_COMPLETED_AT, now()->toIso8601String());
    GithubAccount::factory()->inTeam('acme', 'reviewers')->inTeam('acme', 'platform')->create(['login' => 'octocat']);
    $repository = trackedRepository('acme/api');

    $response = $this->get(route('repositories.show', $repository));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('teams', ['acme/reviewers', 'acme/platform']));
});

it('shows the repository\'s own review prompt beside the app-wide one', function () {
    onboardedAccount();
    app(AppSettings::class)->set(AppSettings::REVIEW_PROMPT, 'Review {repository}#{number} carefully.');
    $repository = trackedRepository('acme/api', ['review_prompt' => 'Only check for SQL injection.']);

    $response = $this->get(route('repositories.show', $repository));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('reviewPrompt.custom', 'Only check for SQL injection.')
        ->where('reviewPrompt.app_wide', 'Review {repository}#{number} carefully.')
        ->where('reviewPrompt.max_length', AppSettings::REVIEW_PROMPT_MAX_LENGTH)
        ->where('reviewPrompt.placeholders', AppSettings::REVIEW_PROMPT_PLACEHOLDERS));
});

it('shows no custom review prompt for a repository that follows the app-wide one', function () {
    onboardedAccount();
    $repository = trackedRepository('acme/api', ['review_prompt' => null]);

    $response = $this->get(route('repositories.show', $repository));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('reviewPrompt.custom', null)
        ->where('reviewPrompt.app_wide', AppSettings::DEFAULT_REVIEW_PROMPT));
});

it('links a local checkout that exists on this computer', function () {
    onboardedAccount();
    $repository = trackedRepository('acme/api');
    $checkout = sys_get_temp_dir().'/prism-checkout-'.uniqid();
    File::ensureDirectoryExists($checkout);

    $response = $this->from(route('repositories.show', $repository))
        ->patch(route('repositories.update', $repository), ['local_path' => $checkout]);

    $response->assertRedirect(route('repositories.show', $repository))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Updated acme/api.');
    expect($repository->fresh()->local_path)->toBe($checkout);

    File::deleteDirectory($checkout);
});

it('unlinks the local checkout when the folder is cleared', function () {
    onboardedAccount();
    $repository = trackedRepository('acme/api', ['local_path' => '/Users/octocat/Code/api']);

    $response = $this->patch(route('repositories.update', $repository), ['local_path' => '']);

    $response->assertSessionHasNoErrors()->assertSessionHas('success', 'Updated acme/api.');
    expect($repository->fresh()->local_path)->toBeNull();
});

it('rejects a local checkout that is not a folder on this computer', function (mixed $path, string $error) {
    onboardedAccount();
    $repository = trackedRepository('acme/api', ['local_path' => '/Users/octocat/Code/api']);

    $response = $this->patch(route('repositories.update', $repository), ['local_path' => $path]);

    $response->assertSessionHasErrors(['local_path' => $error]);
    expect($repository->fresh()->local_path)->toBe('/Users/octocat/Code/api');
})->with([
    'missing folder' => ['/Users/octocat/Code/does-not-exist', 'That folder does not exist on this computer.'],
    'too long' => ['/Users/octocat/'.str_repeat('a', 1024), 'The local path field must not be greater than 1024 characters.'],
    'a list instead of a path' => [['/Users/octocat/Code/api'], 'The local path field must be a string.'],
    'a relative path' => ['.', "Enter the folder's full path, like /Users/you/code."],
]);

it('rejects a tracking choice that is not true or false', function () {
    onboardedAccount();
    $repository = trackedRepository('acme/api');

    $response = $this->patch(route('repositories.update', $repository), ['is_tracked' => 'sometimes']);

    $response->assertSessionHasErrors('is_tracked');
    expect($repository->fresh()->is_tracked)->toBeTrue();
});
