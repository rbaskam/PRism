<?php

use App\Services\Changelog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->changelogPath = tempnam(sys_get_temp_dir(), 'changelog');
    config(['prism.changelog_path' => $this->changelogPath]);
    file_put_contents($this->changelogPath, "# Changelog\n\n## [Unreleased]\n\n### Added\n\n- Dark mode\n\n## [1.4.2] - 2026-09-01\n\n### Fixed\n\n- Old fix\n");
});

afterEach(function () {
    @unlink($this->changelogPath);
});

it('prints the next version for the release workflow', function () {
    $this->artisan('changelog', ['action' => 'next-version'])
        ->expectsOutput('1.5.0')
        ->assertSuccessful();
});

it('prints nothing for the next version when nothing is waiting to be released', function () {
    file_put_contents($this->changelogPath, "## [Unreleased]\n\n## [1.4.2] - 2026-09-01\n\n- Old fix\n");

    $exitCode = Artisan::call('changelog', ['action' => 'next-version']);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and(trim(Artisan::output()))->toBe('');
});

it('releases what is waiting as the next version, dated today', function () {
    $this->travelTo('2026-11-09 12:00:00');

    $this->artisan('changelog', ['action' => 'release'])
        ->expectsOutput('1.5.0')
        ->assertSuccessful();

    $changelog = new Changelog($this->changelogPath);
    expect(file_get_contents($this->changelogPath))->toContain('## [1.5.0] - 2026-11-09')
        ->and($changelog->notesFor('1.5.0'))->toBe("### Added\n\n- Dark mode")
        ->and($changelog->notesFor(Changelog::UNRELEASED))->toBe('');
});

it('releases the version and date it is given', function () {
    $this->artisan('changelog', ['action' => 'release', 'version' => '2.0.0', '--date' => '2026-12-01'])
        ->expectsOutput('2.0.0')
        ->assertSuccessful();

    expect(file_get_contents($this->changelogPath))->toContain('## [2.0.0] - 2026-12-01');
});

it('refuses to release when nothing is waiting to be released', function () {
    $markdown = "## [Unreleased]\n\n## [1.4.2] - 2026-09-01\n\n- Old fix\n";
    file_put_contents($this->changelogPath, $markdown);

    $this->artisan('changelog', ['action' => 'release'])
        ->expectsOutputToContain('Nothing to release')
        ->assertFailed();

    expect(file_get_contents($this->changelogPath))->toBe($markdown);
});

it('prints the release notes for a tagged version', function () {
    $this->artisan('changelog', ['action' => 'notes', 'version' => 'v1.4.2'])
        ->expectsOutput("### Fixed\n\n- Old fix")
        ->assertSuccessful();
});

it('fails when asked for notes of a version that is not in the changelog', function () {
    $this->artisan('changelog', ['action' => 'notes', 'version' => '9.9.9'])
        ->expectsOutputToContain('Version 9.9.9 is not in the changelog.')
        ->assertFailed();
});

it('rejects an unknown action', function () {
    $this->artisan('changelog', ['action' => 'publish'])
        ->expectsOutputToContain('Unknown action. Use next-version, release or notes.')
        ->assertExitCode(Command::INVALID);
});
