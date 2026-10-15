<?php

use App\Services\AppLock;
use App\Services\AppSettings;

beforeEach(function () {
    onboardedAccount(['login' => 'octocat']);
    app(AppLock::class)->setPin('2468');
    app(AppSettings::class)->set(AppSettings::APP_LOCK_ENABLED, true);
});

it('asks for the PIN before showing anything', function () {
    visit('/')
        ->assertPathIs('/lock')
        ->fill('#pin', '2468')
        ->click('internal:role=button[name="Unlock"s]')
        ->waitForText('My pull requests')
        ->assertPathIs('/')
        ->assertNoJavaScriptErrors();
});

it('stays locked when the PIN is wrong', function () {
    visit('/')
        ->fill('#pin', '1357')
        ->click('internal:role=button[name="Unlock"s]')
        ->waitForText('That PIN is not right.')
        ->assertPathIs('/lock')
        ->assertNoJavaScriptErrors();
});
