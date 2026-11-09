<?php

use App\Enums\ClaudeAuthMode;
use App\Jobs\PollNotifications;
use App\Jobs\SyncGithub;
use App\Models\GithubAccount;
use App\Models\Repository;
use App\Services\AppSettings;
use App\Services\Claude\ClaudeCode;
use App\Services\Github\GithubCli;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('starts onboarding by offering the ways to connect GitHub that this computer supports', function (bool $cliInstalled, ?string $clientId) {
    config(['services.github.client_id' => $clientId]);
    $this->mock(GithubCli::class, fn (MockInterface $mock) => $mock->shouldReceive('isInstalled')->andReturn($cliInstalled));

    $response = $this->get(route('onboarding.github'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('onboarding/Github')
        ->where('account', null)
        ->where('onboardingCompleted', false)
        ->where('githubCliInstalled', $cliInstalled)
        ->where('deviceFlowAvailable', $clientId !== null)
        ->where('requiredScopes', ['repo', 'read:org', 'notifications']));
})->with([
    'GitHub CLI and device flow' => [true, 'test-client-id'],
    'neither' => [false, null],
]);

it('shows the connected account without its token when reconnecting GitHub after onboarding', function () {
    $this->mock(GithubCli::class, fn (MockInterface $mock) => $mock->shouldReceive('isInstalled')->andReturn(false));
    onboardedAccount([
        'login' => 'octocat',
        'name' => 'The Octocat',
        'avatar_url' => 'https://avatars.example.com/octocat',
        'token' => 'ghp_test_secret_token',
    ]);

    $response = $this->get(route('onboarding.github'));

    $response->assertOk()
        ->assertDontSee('ghp_test_secret_token')
        ->assertInertia(fn (Assert $page) => $page
            ->where('account', ['login' => 'octocat', 'name' => 'The Octocat', 'avatar_url' => 'https://avatars.example.com/octocat'])
            ->where('onboardingCompleted', true));
});

it('shows the tracked repositories and suggests ones the user was asked to review in', function () {
    GithubAccount::factory()->create(['untracked_review_requests' => ['acme/docs', 'acme/mobile']]);
    $web = trackedRepository('acme/web');
    $api = trackedRepository('acme/api');
    Repository::factory()->untracked()->create(['full_name' => 'acme/archived']);

    $response = $this->get(route('onboarding.repositories'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('onboarding/Repositories')
        ->has('tracked', 2)
        ->where('tracked.0.id', $api->id)
        ->where('tracked.0.full_name', 'acme/api')
        ->where('tracked.1.id', $web->id)
        ->where('suggested', ['acme/docs', 'acme/mobile']));
});

it('shows nothing tracked or suggested to a new user', function () {
    GithubAccount::factory()->create(['untracked_review_requests' => null]);

    $response = $this->get(route('onboarding.repositories'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('tracked', [])
        ->where('suggested', []));
});

it('asks for GitHub before choosing repositories or connecting Claude', function (string $route) {
    $response = $this->get(route($route));

    $response->assertRedirect(route('onboarding.github'));
})->with(['onboarding.repositories', 'onboarding.claude']);

it('shows whether Claude Code is ready and the saved settings without the saved key', function () {
    GithubAccount::factory()->create();
    $settings = app(AppSettings::class);
    $settings->set(AppSettings::CLAUDE_AUTH_MODE, ClaudeAuthMode::ApiKey->value);
    $settings->setSecret(AppSettings::CLAUDE_CREDENTIAL, 'sk-ant-test-secret');
    $settings->set(AppSettings::CLAUDE_MODEL, 'sonnet');
    $status = ['installed' => true, 'binary' => '/usr/local/bin/claude', 'version' => '2.1.0 (Claude Code)', 'logged_in' => true, 'auth_method' => 'claude.ai', 'error' => null];
    $this->mock(ClaudeCode::class, fn (MockInterface $mock) => $mock->shouldReceive('status')->andReturn($status));

    $response = $this->get(route('onboarding.claude'));

    $response->assertOk()
        ->assertDontSee('sk-ant-test-secret')
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/Claude')
            ->where('status', $status)
            ->where('settings.auth_mode', 'api_key')
            ->where('settings.has_credential', true)
            ->where('settings.model', 'sonnet'));
});

it('shows why Claude Code cannot be used yet', function () {
    GithubAccount::factory()->create();
    $status = ['installed' => false, 'binary' => null, 'version' => null, 'logged_in' => null, 'auth_method' => null, 'error' => 'Claude Code was not found.'];
    $this->mock(ClaudeCode::class, fn (MockInterface $mock) => $mock->shouldReceive('status')->andReturn($status));

    $response = $this->get(route('onboarding.claude'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('status.installed', false)
        ->where('status.error', 'Claude Code was not found.')
        ->where('settings.auth_mode', null)
        ->where('settings.has_credential', false));
});

it('keeps the original onboarding date when onboarding is finished again', function () {
    Queue::fake([SyncGithub::class, PollNotifications::class]);
    $this->freezeTime();
    onboardedAccount();
    $completedAt = app(AppSettings::class)->get(AppSettings::ONBOARDING_COMPLETED_AT);
    $this->travel(2)->days();

    $response = $this->post(route('onboarding.complete'));

    $response->assertRedirect(route('dashboard'))
        ->assertSessionHas('success', 'All set. Your pull requests are syncing now.');
    expect(app(AppSettings::class)->get(AppSettings::ONBOARDING_COMPLETED_AT))->toBe($completedAt);
    Queue::assertPushed(SyncGithub::class);
    Queue::assertPushed(PollNotifications::class);
});
