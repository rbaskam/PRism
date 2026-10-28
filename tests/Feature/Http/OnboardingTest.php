<?php

use App\Enums\GithubTokenSource;
use App\Jobs\PollNotifications;
use App\Jobs\SyncGithub;
use App\Models\GithubAccount;
use App\Models\GithubNotification;
use App\Models\PendingReviewComment;
use App\Models\PullRequest;
use App\Models\ReviewRun;
use App\Services\AppSettings;
use App\Services\Github\GithubCli;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('sends users who have connected GitHub but not finished onboarding to choose repositories', function () {
    GithubAccount::factory()->create();

    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('onboarding.repositories'));
});

it('connects GitHub with a personal access token', function () {
    Http::fake([
        'api.github.com/user' => Http::response(['id' => 42, 'login' => 'octocat', 'name' => 'The Octocat', 'avatar_url' => 'https://avatars.example/octocat'], 200, ['X-OAuth-Scopes' => 'repo, read:org']),
        'api.github.com/user/teams*' => Http::response([['slug' => 'reviewers', 'name' => 'Reviewers', 'organization' => ['login' => 'acme']]]),
    ]);

    $response = $this->post(route('github.connection.token'), ['token' => ' ghp_abc ']);

    $response->assertRedirect(route('onboarding.repositories'));
    $account = GithubAccount::sole();
    expect($account)
        ->login->toBe('octocat')
        ->token->toBe('ghp_abc')
        ->token_source->toBe(GithubTokenSource::PersonalAccessToken)
        ->scopes->toBe(['repo', 'read:org'])
        ->isMemberOfTeam('acme', 'reviewers')->toBeTrue();
});

it('rejects a token GitHub does not accept', function () {
    Http::fake(['api.github.com/user' => Http::response(['message' => 'Bad credentials'], 401)]);

    $response = $this->post(route('github.connection.token'), ['token' => 'ghp_expired']);

    $response->assertSessionHasErrors(['token' => 'GitHub rejected that token. Check it has not expired.']);
    expect(GithubAccount::count())->toBe(0);
});

it('lets users skip connecting Claude, finish onboarding and start the first sync', function () {
    Queue::fake([SyncGithub::class, PollNotifications::class]);
    GithubAccount::factory()->create();

    $response = $this->post(route('onboarding.complete'));

    $response->assertRedirect(route('dashboard'));
    expect(app(AppSettings::class)->hasCompletedOnboarding())->toBeTrue()
        ->and(app(AppSettings::class)->isClaudeConfigured())->toBeFalse();
    Queue::assertPushed(SyncGithub::class);
    Queue::assertPushed(PollNotifications::class);
});

it('returns to GitHub settings after reconnecting once onboarding is done, keeping the account\'s data', function () {
    Queue::fake([SyncGithub::class, PollNotifications::class]);
    onboardedAccount(['github_id' => 42, 'login' => 'octocat', 'token' => 'ghp_old']);
    $notification = GithubNotification::factory()->create();
    $pullRequest = PullRequest::factory()->awaitingMyReview()->create();
    Http::fake(githubUserResponses(githubUser(42, 'octocat')));

    $response = $this->post(route('github.connection.token'), ['token' => 'ghp_test']);

    $response->assertRedirect(route('settings.github'))
        ->assertSessionHas('success', 'Reconnected to GitHub as @octocat.');
    expect(GithubAccount::sole()->token)->toBe('ghp_test')
        ->and($notification->fresh())->not->toBeNull()
        ->and($pullRequest->fresh()->is_review_requested_from_me)->toBeTrue();
    Queue::assertPushed(SyncGithub::class);
    Queue::assertPushed(PollNotifications::class);
});

it('returns to GitHub settings after reconnecting with the GitHub CLI login', function () {
    Queue::fake([SyncGithub::class, PollNotifications::class]);
    onboardedAccount(['github_id' => 42, 'login' => 'octocat']);
    $this->mock(GithubCli::class, fn (MockInterface $mock) => $mock->shouldReceive('token')->andReturn('gho_test'));
    Http::fake(githubUserResponses(githubUser(42, 'octocat')));

    $response = $this->post(route('github.connection.cli'));

    $response->assertRedirect(route('settings.github'))
        ->assertSessionHas('success', 'Reconnected to GitHub as @octocat.');
    expect(GithubAccount::sole())
        ->token->toBe('gho_test')
        ->token_source->toBe(GithubTokenSource::GithubCli);
});

it('sends the device flow page on to the next step once GitHub sign in is approved', function (bool $onboardingCompleted, string $next) {
    Queue::fake([SyncGithub::class, PollNotifications::class]);
    config(['services.github.client_id' => 'test-client-id']);

    if ($onboardingCompleted) {
        onboardedAccount(['github_id' => 42, 'login' => 'octocat']);
    }

    Http::fake([
        'github.com/login/oauth/access_token' => Http::response(['access_token' => 'gho_test']),
        ...githubUserResponses(githubUser(42, 'octocat')),
    ]);

    $response = $this->postJson(route('github.device.poll'), ['device_code' => 'device-code']);

    $response->assertOk()
        ->assertJson(['status' => 'authorized', 'login' => 'octocat', 'redirect' => route($next)])
        ->assertSessionHas('success');
    expect(GithubAccount::sole()->token_source)->toBe(GithubTokenSource::DeviceFlow);
})->with([
    'during onboarding' => [false, 'onboarding.repositories'],
    'when reconnecting' => [true, 'settings.github'],
]);

it('forgets the previous account\'s notifications and review requests when a different account connects', function () {
    Queue::fake([SyncGithub::class, PollNotifications::class]);
    onboardedAccount(['github_id' => 42, 'login' => 'octocat']);
    $repository = trackedRepository('acme/api');
    GithubNotification::factory()->create();
    $pullRequest = PullRequest::factory()->for($repository)->awaitingTeamReview()->approvedByMe()->authoredByMe()->create(['is_assigned_to_me' => true]);
    $reviewRun = ReviewRun::factory()->for($pullRequest)->create();
    PendingReviewComment::factory()->for($pullRequest)->create();
    Http::fake(githubUserResponses(githubUser(7, 'monalisa')));

    $response = $this->post(route('github.connection.token'), ['token' => 'ghp_test']);

    $response->assertRedirect(route('settings.github'))
        ->assertSessionHas('success', 'Connected to GitHub as @monalisa. Notifications and review requests from the previous account were cleared.');
    expect(GithubAccount::sole()->login)->toBe('monalisa')
        ->and(GithubNotification::count())->toBe(0)
        ->and(PendingReviewComment::count())->toBe(0)
        ->and($pullRequest->fresh())
        ->is_review_requested_from_me->toBeFalse()
        ->is_review_requested_directly->toBeFalse()
        ->requested_via_teams->toBe([])
        ->is_authored_by_me->toBeFalse()
        ->is_assigned_to_me->toBeFalse()
        ->my_review_state->toBeNull()
        ->and($repository->fresh()->is_tracked)->toBeTrue()
        ->and($reviewRun->fresh())->not->toBeNull();
    Queue::assertPushed(SyncGithub::class);
});

it('forgets the previous account when a different one connects after it was disconnected', function () {
    Queue::fake([SyncGithub::class, PollNotifications::class]);
    app(AppSettings::class)->set(AppSettings::ONBOARDING_COMPLETED_AT, now()->toIso8601String());
    Http::fake([
        'api.github.com/user' => Http::sequence()->push(...githubUser(42, 'octocat'))->push(...githubUser(7, 'monalisa')),
        'api.github.com/user/teams*' => Http::response([]),
    ]);
    $this->post(route('github.connection.token'), ['token' => 'ghp_test']);
    GithubNotification::factory()->create();
    $this->delete(route('github.connection.destroy'))->assertRedirect(route('onboarding.github'));

    $this->post(route('github.connection.token'), ['token' => 'ghp_other'])->assertRedirect(route('settings.github'));

    expect(GithubAccount::sole()->login)->toBe('monalisa')
        ->and(GithubNotification::count())->toBe(0);
});

/**
 * GitHub's answer to GET /user for an account: its body, status and headers.
 *
 * @return array{0: array<string, mixed>, 1: int, 2: array<string, string>}
 */
function githubUser(int $id, string $login): array
{
    return [['id' => $id, 'login' => $login, 'name' => null, 'avatar_url' => null], 200, ['X-OAuth-Scopes' => 'repo, read:org, notifications']];
}

/**
 * Fake GitHub's /user and /user/teams for an account.
 *
 * @param  array{0: array<string, mixed>, 1: int, 2: array<string, string>}  $user
 * @return array<string, mixed>
 */
function githubUserResponses(array $user): array
{
    return [
        'api.github.com/user' => Http::response(...$user),
        'api.github.com/user/teams*' => Http::response([]),
    ];
}
