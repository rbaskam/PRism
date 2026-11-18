<?php

use App\Actions\RefreshGithubAccount;
use App\Models\GithubAccount;
use App\Models\Repository;
use Illuminate\Http\Client\Request;
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

it('finds the open pull requests that mention the user and the ones they commented on', function () {
    $account = GithubAccount::factory()->create(['login' => 'octocat']);
    Http::fake([
        'api.github.com/user/teams*' => Http::response([]),
        'api.github.com/search/issues*' => fn (Request $request) => Http::response(['items' => match (true) {
            str_contains($request['q'], 'mentions:@me') => [['html_url' => 'https://github.com/acme/api/pull/1']],
            str_contains($request['q'], 'commenter:@me') => [['html_url' => 'https://github.com/acme/web/pull/2']],
            default => [],
        }]),
    ]);

    app(RefreshGithubAccount::class)->handle($account);

    expect($account->refresh())
        ->mentioned_in->toBe(['https://github.com/acme/api/pull/1'])
        ->commented_in->toBe(['https://github.com/acme/web/pull/2']);
});

it('counts the user as a member of the teams above their nested teams', function () {
    $account = GithubAccount::factory()->create(['login' => 'octocat', 'teams' => []]);
    Http::fake([
        'api.github.com/user/teams*' => Http::response([
            ['slug' => 'backend', 'name' => 'Backend', 'organization' => ['login' => 'acme'], 'parent' => ['slug' => 'engineering', 'name' => 'Engineering']],
            ['slug' => 'payments', 'name' => 'Payments', 'organization' => ['login' => 'acme'], 'parent' => ['slug' => 'squads', 'name' => 'Squads']],
        ]),
        'api.github.com/orgs/acme/teams/engineering' => Http::response(['slug' => 'engineering', 'name' => 'Engineering', 'parent' => ['slug' => 'everyone', 'name' => 'Everyone']]),
        'api.github.com/orgs/acme/teams/everyone' => Http::response(['slug' => 'everyone', 'name' => 'Everyone', 'parent' => null]),
        'api.github.com/orgs/acme/teams/squads' => Http::response(['message' => 'Not Found'], 404),
        'api.github.com/search/issues*' => Http::response(['items' => []]),
    ]);

    app(RefreshGithubAccount::class)->handle($account);

    expect(collect($account->refresh()->teams)->pluck('slug')->all())
        ->toBe(['backend', 'engineering', 'everyone', 'payments', 'squads']);
});
