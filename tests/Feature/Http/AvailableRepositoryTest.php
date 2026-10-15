<?php

use App\Models\GithubAccount;
use Illuminate\Support\Facades\Http;

/**
 * @return array<string, mixed>
 */
function githubRepository(int $id, string $owner, string $name): array
{
    return [
        'id' => $id,
        'name' => $name,
        'full_name' => "{$owner}/{$name}",
        'owner' => ['login' => $owner],
        'html_url' => "https://github.com/{$owner}/{$name}",
        'default_branch' => 'main',
        'private' => true,
        'archived' => false,
        'pushed_at' => '2026-10-01T00:00:00Z',
        'description' => null,
    ];
}

it('lists organisation repositories the user listing leaves out, without duplicates', function () {
    Http::preventStrayRequests();
    GithubAccount::factory()->create();
    Http::fake([
        'api.github.com/user/repos*' => Http::response([githubRepository(1, 'octocat', 'dotfiles'), githubRepository(2, 'acme', 'api')]),
        'api.github.com/user/orgs*' => Http::response([['login' => 'acme']]),
        'api.github.com/orgs/acme/repos*' => Http::response([githubRepository(2, 'acme', 'api'), githubRepository(3, 'acme', 'web')]),
    ]);

    $response = $this->getJson(route('github.repositories'));

    $response->assertOk()->assertJsonCount(3, 'repositories')->assertJsonPath('warnings', []);
});

it('links a classic token to its SSO authorisation when an organisation needs it', function () {
    Http::preventStrayRequests();
    GithubAccount::factory()->create(['token' => 'ghp_classic']);
    Http::fake([
        'api.github.com/user/repos*' => Http::response([githubRepository(1, 'octocat', 'dotfiles')], 200, ['X-GitHub-SSO' => 'partial-results; organizations=42']),
        'api.github.com/user/orgs*' => Http::response([]),
        'api.github.com/organizations/42' => Http::response(['login' => 'acme']),
    ]);

    $response = $this->getJson(route('github.repositories'));

    $response->assertOk()
        ->assertJsonCount(1, 'repositories')
        ->assertJsonPath('warnings.0.url', 'https://github.com/settings/tokens');
    expect($response->json('warnings.0.message'))->toStartWith('acme uses single sign-on');
});

it('sends GitHub CLI and app tokens to the organisation sign-in page when it needs SSO', function () {
    Http::preventStrayRequests();
    GithubAccount::factory()->create(['token' => 'gho_cli']);
    Http::fake([
        'api.github.com/user/repos*' => Http::response([], 200, ['X-GitHub-SSO' => 'partial-results; organizations=42']),
        'api.github.com/user/orgs*' => Http::response([]),
        'api.github.com/organizations/42' => Http::response(['login' => 'acme']),
    ]);

    $response = $this->getJson(route('github.repositories'));

    $response->assertJsonPath('warnings.0.url', 'https://github.com/orgs/acme/sso');
});

it('warns that fine-grained tokens only see one owner', function () {
    Http::preventStrayRequests();
    GithubAccount::factory()->create(['token' => 'github_pat_abc']);
    Http::fake([
        'api.github.com/user/repos*' => Http::response([]),
        'api.github.com/user/orgs*' => Http::response([]),
    ]);

    $response = $this->getJson(route('github.repositories'));

    expect($response->json('warnings.0.message'))->toStartWith('You connected with a fine-grained token');
});
