<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('relaunches the desktop app once it has answered', function () {
    config(['nativephp-internal.running' => true, 'nativephp-internal.api_url' => 'http://native.test/api/']);
    Http::fake(['native.test/api/app/relaunch' => Http::response()]);
    $this->withoutDefer();

    $response = $this->postJson(route('app.restart'));

    $response->assertOk()->assertExactJson(['restarting' => true]);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://native.test/api/app/relaunch');
});

it('does nothing outside the desktop app', function () {
    $response = $this->postJson(route('app.restart'));

    $response->assertOk()->assertExactJson(['restarting' => false]);
    Http::assertNothingSent();
});

it('only offers the restart in the installed desktop app, not while developing', function (bool $isNative, bool $debug, bool $expected) {
    onboardedAccount();
    config(['nativephp-internal.running' => $isNative, 'app.debug' => $debug]);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('recoversFromServerProblems', $expected));
})->with([
    'installed desktop app' => [true, false, true],
    'desktop app while developing' => [true, true, false],
    'in a browser' => [false, false, false],
]);
