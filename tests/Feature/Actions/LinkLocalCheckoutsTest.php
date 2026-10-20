<?php

use App\Actions\LinkLocalCheckouts;
use App\Models\Repository;
use Illuminate\Support\Facades\File;

function makeCheckout(string $root, string $relativePath, string $remote): string
{
    $path = $root.'/'.$relativePath;
    File::ensureDirectoryExists($path.'/.git');
    File::put($path.'/.git/config', "[core]\n\tbare = false\n[remote \"origin\"]\n\turl = {$remote}\n\tfetch = +refs/heads/*:refs/remotes/origin/*\n");

    return $path;
}

it('links checkouts in a folder to the tracked repositories they clone', function () {
    $root = sys_get_temp_dir().'/prism-scan-'.uniqid();
    $api = makeCheckout($root, 'work/storefront', 'git@github.com:acme/storefront.git');
    makeCheckout($root, 'other-thing', 'https://github.com/someone/other-thing.git');
    makeCheckout($root, 'node_modules/ignored', 'git@github.com:acme/web.git');
    $tracked = Repository::factory()->create(['full_name' => 'acme/storefront', 'local_path' => null]);
    Repository::factory()->create(['full_name' => 'acme/web', 'local_path' => null]);

    $result = app(LinkLocalCheckouts::class)->handle($root);

    expect($tracked->fresh()->local_path)->toBe($api)
        ->and(collect($result['linked'])->pluck('full_name')->all())->toBe(['acme/storefront'])
        ->and(collect($result['untracked'])->pluck('full_name')->all())->toBe(['someone/other-thing']);

    File::deleteDirectory($root);
});

it('keeps a checkout the user already linked', function () {
    $root = sys_get_temp_dir().'/prism-scan-'.uniqid();
    $existing = makeCheckout($root, 'mine', 'git@github.com:acme/api.git');
    makeCheckout($root, 'copy-of-api', 'git@github.com:acme/api.git');
    $repository = Repository::factory()->create(['full_name' => 'acme/api', 'local_path' => $existing]);

    app(LinkLocalCheckouts::class)->handle($root);

    expect($repository->fresh()->local_path)->toBe($existing);

    File::deleteDirectory($root);
});
