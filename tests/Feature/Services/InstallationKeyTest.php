<?php

use App\Services\InstallationKey;
use Illuminate\Support\Facades\File;

it('creates a key on first launch and reuses it after updates', function () {
    $path = sys_get_temp_dir().'/prism-key-'.uniqid().'/installation.key';

    $firstLaunch = new InstallationKey($path);
    $key = $firstLaunch->key();
    $afterUpdate = new InstallationKey($path);

    expect($key)->toStartWith('base64:')
        ->and($firstLaunch->wasCreated())->toBeTrue()
        ->and($afterUpdate->key())->toBe($key)
        ->and($afterUpdate->wasCreated())->toBeFalse()
        ->and(substr(sprintf('%o', fileperms($path)), -4))->toBe('0600');

    File::deleteDirectory(dirname($path));
})->skipOnWindows();

it('replaces a key file that was cut short instead of failing every request', function (string $contents) {
    $path = sys_get_temp_dir().'/prism-key-'.uniqid().'/installation.key';
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $contents);

    $installationKey = new InstallationKey($path);
    $key = $installationKey->key();

    expect($key)->toStartWith('base64:')
        ->and(strlen(base64_decode(substr($key, 7))))->toBe(32)
        ->and($installationKey->wasCreated())->toBeTrue()
        ->and(trim(File::get($path)))->toBe($key)
        ->and(File::files(dirname($path)))->toHaveCount(1);

    File::deleteDirectory(dirname($path));
})->with([
    'empty' => '',
    'cut short' => 'base64:c2hvcnQ=',
    'not a key' => 'not-a-key',
]);

it('keeps a key that is still usable', function () {
    $path = sys_get_temp_dir().'/prism-key-'.uniqid().'/installation.key';
    $key = 'base64:'.base64_encode(random_bytes(32));
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $key."\n");

    $installationKey = new InstallationKey($path);

    expect($installationKey->key())->toBe($key)
        ->and($installationKey->wasCreated())->toBeFalse();

    File::deleteDirectory(dirname($path));
});
