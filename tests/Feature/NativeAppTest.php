<?php

use App\Jobs\SyncGithub;
use App\Providers\NativeAppServiceProvider;
use App\Services\AppSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Native\Desktop\DataObjects\QueueConfig;
use Native\Desktop\Facades\QueueWorker;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as NativeWindow;

beforeEach(function () {
    config(['nativephp-internal.api_url' => 'http://native.test/api/']);
    Http::preventStrayRequests();
    Http::fake(['native.test/api/*' => Http::response()]);
    Queue::fake();
    $this->queueWorkers = QueueWorker::fake();
    Window::fake()->alwaysReturnWindows([new NativeWindow(NativeAppServiceProvider::MAIN_WINDOW)]);
});

it('syncs with GitHub when the app starts', function () {
    onboardedAccount();

    app(NativeAppServiceProvider::class)->boot();

    Queue::assertPushed(SyncGithub::class);
});

it('starts the review runners the user chose beyond the first, which NativePHP starts itself', function () {
    app(AppSettings::class)->set(AppSettings::REVIEW_RUNNERS, 3);

    app(NativeAppServiceProvider::class)->boot();

    expect(collect($this->queueWorkers->ups)->map(fn (QueueConfig $config): string => $config->alias)->all())->toBe(['reviews-2', 'reviews-3']);
    $this->queueWorkers->assertUp(fn (QueueConfig $config): bool => $config->queuesToConsume === ['reviews'] && $config->timeout === 1800);
});

it('waits for GitHub to be connected before syncing', function () {
    app(NativeAppServiceProvider::class)->boot();

    Queue::assertNotPushed(SyncGithub::class);
});

it('keeps links from opening new app windows or taking the window to another site', function () {
    $window = new NativeWindow(NativeAppServiceProvider::MAIN_WINDOW);
    Window::fake()->alwaysReturnWindows([$window]);

    app(NativeAppServiceProvider::class)->boot();

    expect($window->toArray())
        ->suppressNewWindows->toBeTrue()
        ->preventLeaveDomain->toBeTrue();
});

it('turns developer tools off in release builds', function (string $environment, bool $devTools) {
    app()->detectEnvironment(fn (): string => $environment);
    $window = new NativeWindow(NativeAppServiceProvider::MAIN_WINDOW);
    Window::fake()->alwaysReturnWindows([$window]);

    app(NativeAppServiceProvider::class)->boot();

    expect($window->toArray()['webPreferences'])->toBe(['devTools' => $devTools]);
})->with([
    'release build' => ['production', false],
    'running from source' => ['local', true],
]);

it('keeps function arguments such as tokens out of stack traces', function () {
    expect(app(NativeAppServiceProvider::class)->phpIni())->toHaveKey('zend.exception_ignore_args', '1');
});
