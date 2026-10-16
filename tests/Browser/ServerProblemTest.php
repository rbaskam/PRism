<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/*
 * When the desktop app's server has stopped, another program on its port answers instead, e.g. with a bare
 * "Method Not Allowed". These tests stand in for that program by replacing the routes the page calls.
 */
beforeEach(function () {
    onboardedAccount(['login' => 'octocat']);
    config([
        'app.debug' => false,
        'nativephp-internal.running' => true,
        'nativephp-internal.api_url' => 'http://native.test/api/',
    ]);
    Http::fake(['native.test/*' => Http::response()]);
    Route::post('sync', fn () => response('Method Not Allowed', 405, ['Content-Type' => 'text/plain']));
});

it('explains that the server stopped and restarts the app', function () {
    $page = visit('/');
    trackRequests($page);

    $page->click('Sync with GitHub')
        ->waitForText('PRism stopped responding')
        ->assertDontSee('Method Not Allowed')
        ->click('internal:role=button[name="Restart PRism"i]')
        ->waitForText('Restarting…');
    waitForRequests($page);

    expect(Http::recorded(fn (Request $request): bool => $request->url() === 'http://native.test/api/app/relaunch'))->toHaveCount(1);
});

it('says the app will restart by itself when the server cannot take the restart either', function () {
    Route::post('app/restart', fn () => response('Method Not Allowed', 405, ['Content-Type' => 'text/plain']));

    visit('/')
        ->click('Sync with GitHub')
        ->waitForText('PRism stopped responding')
        ->click('internal:role=button[name="Restart PRism"i]')
        ->waitForText('will restart by itself within a minute')
        ->assertNoJavaScriptErrors();
});
