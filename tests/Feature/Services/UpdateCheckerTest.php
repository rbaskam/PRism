<?php

use App\Services\UpdateChecker;
use Illuminate\Support\Facades\Http;

it('reports a newer release on GitHub with the installer for this computer', function () {
    Http::preventStrayRequests();
    config(['prism.version' => '0.1.0', 'prism.repository' => 'rbaskam/PRism']);
    Http::fake(['api.github.com/repos/rbaskam/PRism/releases/latest' => Http::response([
        'tag_name' => 'v0.2.0',
        'name' => 'v0.2.0',
        'html_url' => 'https://github.com/rbaskam/PRism/releases/tag/v0.2.0',
        'body' => '### Added\n\n- Things',
        'published_at' => '2026-10-13T10:00:00Z',
        'assets' => [
            ['name' => 'PRism-0.2.0-arm64.dmg', 'browser_download_url' => 'https://example.com/arm64.dmg'],
            ['name' => 'PRism-0.2.0-x64.dmg', 'browser_download_url' => 'https://example.com/x64.dmg'],
            ['name' => 'PRism-0.2.0-setup-x64.exe', 'browser_download_url' => 'https://example.com/setup.exe'],
            ['name' => 'PRism-0.2.0.AppImage', 'browser_download_url' => 'https://example.com/linux.AppImage'],
        ],
    ])]);
    $updates = app(UpdateChecker::class);

    $latest = $updates->check();

    expect($latest['version'])->toBe('0.2.0')
        ->and($updates->updateIsAvailable())->toBeTrue()
        ->and($latest['download_url'])->toStartWith('https://example.com/');
});

it('does not offer an update when running the latest release', function () {
    Http::preventStrayRequests();
    config(['prism.version' => '0.2.0', 'prism.repository' => 'rbaskam/PRism']);
    Http::fake(['api.github.com/repos/rbaskam/PRism/releases/latest' => Http::response([
        'tag_name' => 'v0.2.0', 'name' => '', 'html_url' => 'https://github.com/x', 'body' => '', 'assets' => [],
    ])]);

    app(UpdateChecker::class)->check();

    expect(app(UpdateChecker::class)->updateIsAvailable())->toBeFalse();
});

it('shows what is new only after an update, not on a fresh install', function () {
    config(['prism.version' => '0.1.0']);
    $updates = app(UpdateChecker::class);

    expect($updates->unseenVersion())->toBeNull();

    config(['prism.version' => '0.2.0']);

    expect($updates->unseenVersion())->toBe('0.2.0');

    $updates->markVersionSeen();

    expect($updates->unseenVersion())->toBeNull();
});
