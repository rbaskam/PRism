<?php

use App\Services\AppSettings;
use App\Services\UpdateChecker;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['prism.version' => 'v0.1.0', 'prism.repository' => 'acme/prism']);
    $this->changelogPath = tempnam(sys_get_temp_dir(), 'changelog');
    config(['prism.changelog_path' => $this->changelogPath]);
    onboardedAccount();
});

afterEach(function () {
    @unlink($this->changelogPath);
});

it('shows the running version, the last update check and the changelog', function () {
    file_put_contents($this->changelogPath, "# Changelog\n\n## [Unreleased]\n\n### Fixed\n\n- Coming soon\n\n## [0.2.0] - 2026-10-20\n\n### Added\n\n- Dark mode\n\n## [0.1.0] - 2026-10-01\n\n### Added\n\n- First release\n");
    $settings = app(AppSettings::class);
    $settings->set(UpdateChecker::LATEST_RELEASE, [
        'version' => '0.2.0',
        'name' => 'v0.2.0',
        'url' => 'https://example.com/releases/v0.2.0',
        'download_url' => 'https://example.com/PRism-0.2.0.dmg',
        'notes' => '',
        'published_at' => '2026-10-20T10:00:00Z',
    ]);
    $settings->set(UpdateChecker::CHECKED_AT, '2026-11-01T09:00:00+00:00');

    $response = $this->get(route('settings.about'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('settings/About')
        ->where('currentVersion', '0.1.0')
        ->where('latestRelease.version', '0.2.0')
        ->where('updateAvailable', true)
        ->where('checkedAt', '2026-11-01T09:00:00+00:00')
        ->where('repositoryUrl', 'https://github.com/acme/prism')
        ->has('releases', 2)
        ->where('releases.0.version', '0.2.0')
        ->where('releases.0.notes', "### Added\n\n- Dark mode")
        ->where('unreleased', "### Fixed\n\n- Coming soon"));
});

it('tells the user what checking for updates found', function (array $githubResponse, int $status, string $message) {
    Http::fake(['api.github.com/repos/acme/prism/releases/latest' => Http::response($githubResponse, $status)]);

    $response = $this->from(route('settings.about'))->post(route('updates.check'));

    $response->assertRedirect(route('settings.about'))->assertSessionHas('success', $message);
})->with([
    'a newer release' => [['tag_name' => 'v0.2.0', 'name' => 'v0.2.0', 'html_url' => 'https://example.com/releases/v0.2.0', 'assets' => []], 200, 'Version 0.2.0 is available.'],
    'the running release' => [['tag_name' => 'v0.1.0', 'name' => 'v0.1.0', 'html_url' => 'https://example.com/releases/v0.1.0', 'assets' => []], 200, "You're on the latest version (0.1.0)."],
    'no releases yet' => [['message' => 'Not Found'], 404, 'No releases have been published yet.'],
]);

it('explains that GitHub refused the update check', function () {
    Http::fake(['api.github.com/repos/acme/prism/releases/latest' => Http::response(['message' => 'API rate limit exceeded'], 403)]);

    $response = $this->from(route('settings.about'))->post(route('updates.check'));

    $response->assertRedirect(route('settings.about'))->assertSessionHas('error', 'Could not check for updates: GitHub returned 403.');
    expect(app(AppSettings::class)->get(UpdateChecker::LATEST_RELEASE))->toBeNull();
});

it('stops showing what is new once the user dismisses it', function () {
    app(AppSettings::class)->set(UpdateChecker::LAST_SEEN_VERSION, '0.0.9');

    $this->from(route('settings.about'))->post(route('whats-new.dismiss'))->assertRedirect(route('settings.about'));

    expect(app(UpdateChecker::class)->unseenVersion())->toBeNull()
        ->and(app(AppSettings::class)->get(UpdateChecker::LAST_SEEN_VERSION))->toBe('0.1.0');
});
