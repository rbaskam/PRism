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
