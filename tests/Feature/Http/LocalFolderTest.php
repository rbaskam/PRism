<?php

use App\Services\AppSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    onboardedAccount(['login' => 'octocat']);
});

it('asks the desktop app to pick a folder and returns the one chosen', function () {
    config(['nativephp-internal.running' => true, 'nativephp-internal.api_url' => 'http://native.test/api/']);
    Http::fake(['native.test/api/dialog/open' => Http::response(['result' => ['/Users/octocat/Code/api']])]);

    $response = $this->postJson(route('local-folders.store'));

    $response->assertOk()->assertExactJson(['path' => '/Users/octocat/Code/api']);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://native.test/api/dialog/open'
        && $request['title'] === 'Choose the local checkout of this repository'
        && $request['buttonLabel'] === 'Use this folder'
        && in_array('openDirectory', $request['properties'], true)
        && ! in_array('openFile', $request['properties'], true));
});

it('titles the folder dialog as asked', function () {
    config(['nativephp-internal.running' => true, 'nativephp-internal.api_url' => 'http://native.test/api/']);
    Http::fake(['native.test/api/dialog/open' => Http::response(['result' => ['/Users/octocat/Code']])]);

    $this->postJson(route('local-folders.store'), ['title' => 'Choose the folder with your code'])->assertOk();

    Http::assertSent(fn (Request $request): bool => $request['title'] === 'Choose the folder with your code');
});

it('returns no folder when the dialog is cancelled', function () {
    config(['nativephp-internal.running' => true, 'nativephp-internal.api_url' => 'http://native.test/api/']);
    Http::fake(['native.test/api/dialog/open' => Http::response(['result' => []])]);

    $response = $this->postJson(route('local-folders.store'));

    $response->assertOk()->assertExactJson(['path' => null]);
});

it('rejects a dialog title that is too long without opening the dialog', function () {
    config(['nativephp-internal.running' => true, 'nativephp-internal.api_url' => 'http://native.test/api/']);

    $response = $this->postJson(route('local-folders.store'), ['title' => str_repeat('a', 121)]);

    $response->assertUnprocessable()->assertJsonValidationErrors('title');
    Http::assertNothingSent();
});

it('has no folder dialog outside the desktop app', function () {
    $response = $this->postJson(route('local-folders.store'));

    $response->assertNotFound();
    Http::assertNothingSent();
});

it('scans a folder for checkouts of tracked repositories and remembers the folder', function () {
    $root = sys_get_temp_dir().'/prism-code-'.uniqid();
    File::ensureDirectoryExists($root.'/api/.git');
    File::put($root.'/api/.git/config', "[remote \"origin\"]\n\turl = git@github.com:acme/api.git\n");
    File::ensureDirectoryExists($root.'/side-project/.git');
    File::put($root.'/side-project/.git/config', "[remote \"origin\"]\n\turl = https://github.com/octocat/side-project.git\n");
    $repository = trackedRepository('acme/api', ['local_path' => null]);

    $response = $this->postJson(route('local-checkouts.scan'), ['folder' => $root]);

    $response->assertOk()->assertExactJson([
        'linked' => [['full_name' => 'acme/api', 'path' => $root.'/api']],
        'already_linked' => [],
        'untracked' => [['full_name' => 'octocat/side-project', 'path' => $root.'/side-project']],
    ]);
    expect($repository->fresh()->local_path)->toBe($root.'/api')
        ->and(app(AppSettings::class)->get(AppSettings::LOCAL_CODE_FOLDER))->toBe($root);

    File::deleteDirectory($root);
});

it('rejects a folder to scan that is missing or not on this computer', function (array $input, string $error) {
    $repository = trackedRepository('acme/api', ['local_path' => null]);

    $response = $this->postJson(route('local-checkouts.scan'), $input);

    $response->assertUnprocessable()->assertJsonValidationErrors(['folder' => $error]);
    expect(app(AppSettings::class)->get(AppSettings::LOCAL_CODE_FOLDER))->toBeNull()
        ->and($repository->fresh()->local_path)->toBeNull();
})->with([
    'no folder' => [[], 'The folder field is required.'],
    'missing folder' => [['folder' => '/Users/octocat/Code/does-not-exist'], 'That folder does not exist on this computer.'],
]);
