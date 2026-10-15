<?php

use App\Services\AppLock;
use App\Services\AppSettings;
use Illuminate\Support\Facades\Http;

function enableAppLock(string $pin = '2468', int $autoLockMinutes = 15): void
{
    $settings = app(AppSettings::class);
    $settings->set(AppSettings::APP_LOCK_ENABLED, true);
    $settings->set(AppSettings::APP_LOCK_AUTO_LOCK_MINUTES, $autoLockMinutes);
    app(AppLock::class)->setPin($pin);
}

it('shows the lock screen until the app is unlocked', function () {
    onboardedAccount();
    enableAppLock();

    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('lock.show'));
});

it('unlocks with the right PIN and returns to the page the user wanted', function () {
    onboardedAccount();
    enableAppLock('2468');
    $this->get(route('inbox.index'));

    $response = $this->post(route('lock.pin'), ['pin' => '2468']);

    $response->assertRedirect(route('inbox.index'));
    $this->get(route('dashboard'))->assertOk();
});

it('rejects a wrong PIN', function () {
    onboardedAccount();
    enableAppLock('2468');

    $response = $this->post(route('lock.pin'), ['pin' => '1111']);

    $response->assertSessionHasErrors(['pin' => 'That PIN is not right.']);
    $this->get(route('dashboard'))->assertRedirect(route('lock.show'));
});

it('stops accepting PINs after five wrong attempts', function () {
    onboardedAccount();
    enableAppLock('2468');

    foreach (range(1, 5) as $attempt) {
        $this->post(route('lock.pin'), ['pin' => '0000']);
    }
    $response = $this->post(route('lock.pin'), ['pin' => '2468']);

    expect(session('errors')->first('pin'))->toStartWith('Too many attempts.');
    $this->get(route('dashboard'))->assertRedirect(route('lock.show'));
});

it('locks again after the auto-lock time without activity', function () {
    onboardedAccount();
    enableAppLock('2468', autoLockMinutes: 5);
    $this->post(route('lock.pin'), ['pin' => '2468']);

    $this->travel(6)->minutes();

    $this->get(route('dashboard'))->assertRedirect(route('lock.show'));
});

it('locks every window when the app starts', function () {
    onboardedAccount();
    enableAppLock('2468');
    $this->post(route('lock.pin'), ['pin' => '2468']);

    app(AppLock::class)->lockEverywhere();

    $this->get(route('dashboard'))->assertRedirect(route('lock.show'));
});

it('unlocks with Touch ID on a Mac', function () {
    onboardedAccount();
    enableAppLock();
    app(AppSettings::class)->set(AppSettings::APP_LOCK_USE_BIOMETRICS, true);
    config(['nativephp-internal.running' => true]);
    Http::fake([
        '*/api/system/can-prompt-touch-id' => Http::response(['result' => true]),
        '*/api/system/prompt-touch-id' => Http::response(['result' => true]),
    ]);

    $response = $this->post(route('lock.biometrics'));

    if (PHP_OS_FAMILY !== 'Darwin') {
        $response->assertSessionHas('error');

        return;
    }

    $response->assertRedirect(route('dashboard'));
    $this->get(route('dashboard'))->assertOk();
})->skipOnWindows();

it('needs the current PIN to turn the lock off', function () {
    onboardedAccount();
    enableAppLock('2468');
    $this->post(route('lock.pin'), ['pin' => '2468']);

    $response = $this->put(route('settings.security.update'), [
        'lock_enabled' => false,
        'current_pin' => '9999',
        'use_biometrics' => false,
        'auto_lock_minutes' => 15,
        'lock_on_launch' => true,
    ]);

    $response->assertSessionHasErrors(['current_pin' => 'Your current PIN is not right.']);
    expect(app(AppSettings::class)->isAppLockEnabled())->toBeTrue();
});

it('turns the lock on with a new PIN without locking the user out', function () {
    onboardedAccount();

    $response = $this->put(route('settings.security.update'), [
        'lock_enabled' => true,
        'pin' => '135790',
        'pin_confirmation' => '135790',
        'use_biometrics' => false,
        'auto_lock_minutes' => 30,
        'lock_on_launch' => true,
    ]);

    $response->assertSessionHas('success', 'App lock saved.');
    expect(app(AppLock::class)->pinMatches('135790'))->toBeTrue();
    $this->get(route('dashboard'))->assertOk();
});
