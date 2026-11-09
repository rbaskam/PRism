<?php

use App\Actions\RefreshGithubAccount;
use App\Models\GithubAccount;
use App\Models\Repository;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('refreshes the user\'s teams and finds review requests in repositories they do not track', function () {
    $account = GithubAccount::factory()->create(['login' => 'octocat', 'teams' => [], 'teams_synced_at' => null]);
    trackedRepository('acme/web');
    Repository::factory()->untracked()->create(['owner' => 'acme', 'name' => 'infra', 'full_name' => 'acme/infra']);
    Http::fake([
        'api.github.com/user/teams*' => Http::response([
            ['slug' => 'reviewers', 'name' => 'Reviewers', 'organization' => ['login' => 'acme']],
        ]),
        'api.github.com/search/issues*' => Http::response(['items' => [
            ['repository_url' => 'https://api.github.com/repos/acme/web'],
            ['repository_url' => 'https://api.github.com/repos/acme/mobile'],
            ['repository_url' => 'https://api.github.com/repos/acme/mobile'],
            ['repository_url' => 'https://api.github.com/repos/acme/infra'],
        ]]),
    ]);

    app(RefreshGithubAccount::class)->handle($account);

    $account->refresh();
    expect($account->isMemberOfTeam('acme', 'reviewers'))->toBeTrue()
        ->and($account->teams_synced_at)->not->toBeNull()
        ->and($account->untracked_review_requests)->toBe(['acme/mobile', 'acme/infra']);
});

it('finds no teams but still finds review requests when the token cannot read organisations', function () {
    $account = GithubAccount::factory()->inTeam('acme', 'old-team')->create(['scopes' => ['repo', 'notifications']]);
    Http::fake([
        'api.github.com/user/teams*' => Http::response(['message' => 'Resource not accessible by personal access token'], 403),
        'api.github.com/search/issues*' => Http::response(['items' => [
            ['repository_url' => 'https://api.github.com/repos/acme/mobile'],
        ]]),
    ]);

    app(RefreshGithubAccount::class)->handle($account);

    $account->refresh();
    expect($account->teams)->toBe([])
        ->and($account->untracked_review_requests)->toBe(['acme/mobile']);
});
