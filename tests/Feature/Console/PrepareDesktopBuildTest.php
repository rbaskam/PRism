<?php

use App\Console\Commands\PrepareDesktopBuild;

function entitlementsFile(): string
{
    $path = tempnam(sys_get_temp_dir(), 'entitlements');
    file_put_contents($path, <<<'PLIST'
        <?xml version="1.0" encoding="UTF-8"?>
        <plist version="1.0">
          <dict>
            <key>com.apple.security.cs.allow-jit</key>
            <true/>
          </dict>
        </plist>
        PLIST);

    return $path;
}

it('lets ad hoc signed macOS builds load their own frameworks', function () {
    $path = entitlementsFile();

    $this->artisan('prism:prepare-desktop-build', ['--entitlements' => $path])->assertSuccessful();

    $plist = simplexml_load_file($path);
    $keys = array_map('strval', iterator_to_array($plist->dict->key, false));
    expect($keys)->toBe(['com.apple.security.cs.allow-jit', PrepareDesktopBuild::DISABLE_LIBRARY_VALIDATION])
        ->and($plist->dict->children()->count())->toBe(4);
});

it('leaves the entitlements alone when they are already prepared', function () {
    $path = entitlementsFile();
    $this->artisan('prism:prepare-desktop-build', ['--entitlements' => $path]);
    $prepared = file_get_contents($path);

    $this->artisan('prism:prepare-desktop-build', ['--entitlements' => $path])->assertSuccessful();

    expect(file_get_contents($path))->toBe($prepared);
});
