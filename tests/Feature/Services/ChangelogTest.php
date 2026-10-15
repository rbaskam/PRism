<?php

use App\Services\Changelog;

function changelogWith(string $markdown): Changelog
{
    $path = tempnam(sys_get_temp_dir(), 'changelog');
    file_put_contents($path, $markdown);

    return new Changelog($path);
}

it('works out the next version from what is waiting to be released', function (string $unreleased, string $expected) {
    $changelog = changelogWith("# Changelog\n\n## [Unreleased]\n\n{$unreleased}\n\n## [1.4.2] - 2026-09-01\n\n### Fixed\n\n- Old fix\n");

    expect($changelog->nextVersion())->toBe($expected);
})->with([
    'fixes only' => ["### Fixed\n\n- Crash on launch", '1.4.3'],
    'new feature' => ["### Added\n\n- Dark mode", '1.5.0'],
    'removal' => ["### Removed\n\n- Legacy sync", '2.0.0'],
    'breaking change' => ["### Changed\n\n- BREAKING: new settings format", '2.0.0'],
]);

it('starts at 0.1.0 when nothing has been released yet', function () {
    expect(changelogWith("## [Unreleased]\n\n### Added\n\n- Everything\n")->nextVersion())->toBe('0.1.0');
});

it('has nothing to release when Unreleased is empty', function () {
    expect(changelogWith("## [Unreleased]\n\n## [1.0.0] - 2026-01-01\n\n- First\n")->nextVersion())->toBeNull();
});

it('turns Unreleased into a version and starts a new Unreleased section', function () {
    $changelog = changelogWith("# Changelog\n\n## [Unreleased]\n\n### Added\n\n- Dark mode\n\n## [1.0.0] - 2026-01-01\n\n- First\n");

    $changelog->write($changelog->release('1.1.0', '2026-10-13'));

    expect($changelog->notesFor('Unreleased'))->toBe('')
        ->and($changelog->notesFor('v1.1.0'))->toBe("### Added\n\n- Dark mode")
        ->and($changelog->latestVersion())->toBe('1.1.0');
});
