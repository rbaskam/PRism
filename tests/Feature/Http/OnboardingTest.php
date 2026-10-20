<?php

use App\Enums\GithubTokenSource;
use App\Jobs\PollNotifications;
use App\Jobs\SyncGithub;
use App\Models\GithubAccount;
use App\Services\AppSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

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
