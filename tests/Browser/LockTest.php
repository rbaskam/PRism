<?php

use App\Services\AppLock;
use App\Services\AppSettings;
use Tests\Browser\Support\FakeGithub;

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

it('removes a forgotten PIN with a token for the connected GitHub account', function () {
    (new FakeGithub('octocat'))->fake();

    visit('/')
        ->click('Forgot your PIN?')
        ->assertSee('Personal access token for @octocat')
        ->fill('#recovery-token', 'ghp_test')
        ->click('internal:role=button[name="Remove PIN and unlock"s]')
        ->waitForText('Your PIN was removed and the app lock is off.')
        ->assertPathIs('/settings/security')
        ->assertNoJavaScriptErrors();

    expect(app(AppSettings::class)->isAppLockEnabled())->toBeFalse();
});

it('stays locked when the token belongs to another GitHub account', function () {
    (new FakeGithub('hubot'))->fake();

    visit('/')
        ->click('Forgot your PIN?')
        ->fill('#recovery-token', 'ghp_test')
        ->click('internal:role=button[name="Remove PIN and unlock"s]')
        ->waitForText("That isn't a working token for @octocat.")
        ->assertPathIs('/lock')
        ->assertValue('#recovery-token', '')
        ->assertScript('document.activeElement.id', 'recovery-token')
        ->click('internal:role=button[name="Back to PIN entry"s]')
        ->assertScript('document.activeElement.id', 'pin')
        ->assertNoJavaScriptErrors();

    expect(app(AppLock::class)->pinMatches('2468'))->toBeTrue();
});
