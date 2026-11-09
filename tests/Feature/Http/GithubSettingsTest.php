<?php

use App\Enums\GithubTokenSource;
use App\Models\Repository;
use App\Services\AppSettings;
use App\Services\Github\GithubCli;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;

it('shows the connected account, its teams and the tracked repositories', function () {
    onboardedAccount([
        'login' => 'octocat',
        'token_source' => GithubTokenSource::GithubCli,
        'scopes' => ['repo', 'notifications'],
        'teams' => [['organization' => 'acme', 'slug' => 'reviewers', 'name' => 'Reviewers']],
    ]);
    trackedRepository('acme/web');
    trackedRepository('acme/api');
    Repository::factory()->untracked()->create(['owner' => 'acme', 'name' => 'legacy', 'full_name' => 'acme/legacy']);
    app(AppSettings::class)->set(AppSettings::LOCAL_CODE_FOLDER, '/Users/octocat/Code');
    $this->mock(GithubCli::class, fn (MockInterface $mock) => $mock->shouldReceive('binary')->andReturn('/usr/local/bin/gh'));

    $response = $this->get(route('settings.github'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('settings/Github')
        ->where('account.login', 'octocat')
        ->where('account.token_source', 'GitHub CLI login')
        ->where('account.scopes', ['repo', 'notifications'])
        ->where('account.teams.0.slug', 'reviewers')
        ->has('repositories', 2)
        ->where('repositories.0.full_name', 'acme/api')
        ->where('repositories.1.full_name', 'acme/web')
        ->where('localCodeFolder', '/Users/octocat/Code')
        ->where('githubCli.binary', '/usr/local/bin/gh')
        ->where('githubCli.path_override', null));
});

it('shows no scopes or teams when GitHub has not reported any', function () {
    onboardedAccount(['scopes' => null, 'teams' => null]);
    $this->mock(GithubCli::class, fn (MockInterface $mock) => $mock->shouldReceive('binary')->andReturnNull());

    $response = $this->get(route('settings.github'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('account.scopes', [])
        ->where('account.teams', [])
        ->where('githubCli.binary', null));
});

it('saves the path to the GitHub CLI the user picked', function () {
    onboardedAccount();
    $gh = tempnam(sys_get_temp_dir(), 'gh');
    chmod($gh, 0755);

    $response = $this->from(route('settings.github'))->put(route('settings.github.update'), ['github_cli_path' => $gh]);

    $response->assertRedirect(route('settings.github'))->assertSessionHas('success', 'GitHub settings saved.');
    expect(app(AppSettings::class)->githubCliPath())->toBe($gh);

    unlink($gh);
})->skipOnWindows();

it('rejects a GitHub CLI path with no executable', function () {
    onboardedAccount();
    $notExecutable = tempnam(sys_get_temp_dir(), 'gh');
    chmod($notExecutable, 0644);

    $response = $this->put(route('settings.github.update'), ['github_cli_path' => $notExecutable]);

    $response->assertSessionHasErrors(['github_cli_path' => 'No executable was found at that path.']);
    expect(app(AppSettings::class)->githubCliPath())->toBeNull();

    unlink($notExecutable);
})->skipOnWindows();

it('goes back to finding the GitHub CLI itself when the path is cleared', function () {
    onboardedAccount();
    app(AppSettings::class)->set(AppSettings::GITHUB_CLI_PATH, '/opt/tools/gh');

    $response = $this->put(route('settings.github.update'), ['github_cli_path' => null]);

    $response->assertSessionHasNoErrors()->assertSessionHas('success', 'GitHub settings saved.');
    expect(app(AppSettings::class)->githubCliPath())->toBeNull();
});
