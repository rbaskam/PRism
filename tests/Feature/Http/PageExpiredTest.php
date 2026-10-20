<?php

use App\Providers\AppServiceProvider;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;

/*
 * Laravel skips the CSRF check while tests run, so these routes throw the exception it raises when a page's token
 * no longer matches its session.
 */
beforeEach(function () {
    Route::middleware('web')->post('/_test/expired', fn () => throw new TokenMismatchException('CSRF token mismatch.'));
});

it('sends you back to the page with a message instead of the "419 Page expired" error', function () {
    $response = $this->from('/lock')->post('/_test/expired');

    $response->assertRedirect('/lock')
        ->assertSessionHas('error', 'That page had been open too long, so it was reloaded. Please try again.');
});

it('still answers requests for JSON with a 419', function () {
    $response = $this->postJson('/_test/expired');

    $response->assertStatus(419);
});

it('keeps desktop app sessions for a year, so a lock screen left open overnight still accepts the PIN', function () {
    config(['nativephp-internal.running' => true, 'session.lifetime' => 120]);

    (new AppServiceProvider(app()))->register();

    expect(config('session.lifetime'))->toBe(60 * 24 * 365);
});
