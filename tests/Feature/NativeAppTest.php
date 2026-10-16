<?php

use App\Jobs\SyncGithub;
use App\Providers\NativeAppServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as NativeWindow;

beforeEach(function () {
    config(['nativephp-internal.api_url' => 'http://native.test/api/']);
    Http::preventStrayRequests();
    Http::fake(['native.test/api/*' => Http::response()]);
    Queue::fake();
    Window::fake()->alwaysReturnWindows([new NativeWindow(NativeAppServiceProvider::MAIN_WINDOW)]);
});

it('syncs with GitHub when the app starts', function () {
    onboardedAccount();

    app(NativeAppServiceProvider::class)->boot();

    Queue::assertPushed(SyncGithub::class);
});

it('waits for GitHub to be connected before syncing', function () {
    app(NativeAppServiceProvider::class)->boot();

    Queue::assertNotPushed(SyncGithub::class);
});
