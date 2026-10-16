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

it('stays unlocked while you type, even with no page loads', function () {
    app(AppSettings::class)->set(AppSettings::APP_LOCK_AUTO_LOCK_MINUTES, 5);
    $page = visit('/')
        ->fill('#pin', '2468')
        ->click('internal:role=button[name="Unlock"s]')
        ->waitForText('My pull requests');
    $this->travel(4)->minutes();

    trackRequests($page);
    $page->keys('#app', 'a');
    waitForRequests($page);
    $this->travel(4)->minutes();

    $page->navigate('/repositories')
        ->assertPathIs('/repositories')
        ->assertNoJavaScriptErrors();
});

it('still locks once you stop using the app', function () {
    app(AppSettings::class)->set(AppSettings::APP_LOCK_AUTO_LOCK_MINUTES, 5);
    $page = visit('/')
        ->fill('#pin', '2468')
        ->click('internal:role=button[name="Unlock"s]')
        ->waitForText('My pull requests');
    $this->travel(6)->minutes();

    $page->navigate('/repositories')
        ->assertPathIs('/lock')
        ->assertNoJavaScriptErrors();
});
