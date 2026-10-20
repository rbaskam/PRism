<?php

use App\Enums\NotificationSound;
use App\Services\AppSettings;
use App\Services\DesktopNotifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['nativephp-internal.running' => true]);
    Http::fake(['*/api/notification' => Http::response(['reference' => 'x'])]);
});

it('plays the macOS sound the user chose', function () {
    app(AppSettings::class)->set(AppSettings::NOTIFY_SOUND, NotificationSound::Glass->value);

    app(DesktopNotifier::class)->send('Review requested', 'acme/app: Add rate limiting');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/notification')
        && $request['sound'] === 'Glass'
        && $request['silent'] === false);
});

it('sends a silent notification when the user turned the sound off', function () {
    app(AppSettings::class)->set(AppSettings::NOTIFY_SOUND, NotificationSound::None->value);

    app(DesktopNotifier::class)->send('Review requested', 'acme/app: Add rate limiting');

    Http::assertSent(fn (Request $request): bool => $request['silent'] === true && $request['sound'] === '');
});

it('plays the system\'s own sound when the user picks System default', function () {
    app(AppSettings::class)->set(AppSettings::NOTIFY_SOUND, NotificationSound::Default->value);

    app(DesktopNotifier::class)->send('Review requested', 'acme/app: Add rate limiting');

    Http::assertSent(fn (Request $request): bool => $request['silent'] === false && $request['sound'] === '');
});
