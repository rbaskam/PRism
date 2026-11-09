<?php

use App\Services\AppLock;
use App\Services\AppSettings;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    onboardedAccount();

    $this->lockedWithPin = function (string $pin): void {
        app(AppLock::class)->setPin($pin);
        app(AppSettings::class)->set(AppSettings::APP_LOCK_ENABLED, true);
        $this->post(route('lock.pin'), ['pin' => $pin]);
    };

    $this->securitySettings = fn (array $overrides = []): array => [
        'lock_enabled' => true,
        'use_biometrics' => false,
        'auto_lock_minutes' => 15,
        'lock_on_launch' => true,
        ...$overrides,
    ];
});

it('shows the app lock settings, off by default', function () {
    $response = $this->get(route('settings.security'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('settings/Security')
        ->where('settings.lock_enabled', false)
        ->where('settings.has_pin', false)
        ->where('settings.use_biometrics', false)
        ->where('settings.auto_lock_minutes', 15)
        ->where('settings.lock_on_launch', true)
        ->where('biometrics.available', false)
        ->where('autoLockOptions', [0, 1, 5, 15, 30, 60]));
});

it('shows that a PIN is set once the lock is on', function () {
    ($this->lockedWithPin)('2468');

    $this->get(route('settings.security'))->assertInertia(fn (Assert $page) => $page
        ->where('settings.lock_enabled', true)
        ->where('settings.has_pin', true));
});

it('needs a PIN to turn the lock on for the first time', function () {
    $response = $this->put(route('settings.security.update'), ($this->securitySettings)());

    $response->assertSessionHasErrors('pin');
    expect(app(AppSettings::class)->isAppLockEnabled())->toBeFalse();
});

it('rejects a PIN that is not 4 to 8 digits or not confirmed', function (string $pin, string $confirmation) {
    $response = $this->put(route('settings.security.update'), ($this->securitySettings)(['pin' => $pin, 'pin_confirmation' => $confirmation]));

    $response->assertSessionHasErrors('pin');
    expect(app(AppSettings::class)->get(AppSettings::APP_LOCK_PIN_HASH))->toBeNull()
        ->and(app(AppSettings::class)->isAppLockEnabled())->toBeFalse();
})->with([
    'too short' => ['123', '123'],
    'too long' => ['123456789', '123456789'],
    'not digits' => ['abcd', 'abcd'],
    'not confirmed' => ['2468', '8642'],
]);

it('rejects an auto-lock time that is not offered', function () {
    $response = $this->put(route('settings.security.update'), ($this->securitySettings)([
        'pin' => '2468',
        'pin_confirmation' => '2468',
        'auto_lock_minutes' => 10,
    ]));

    $response->assertSessionHasErrors('auto_lock_minutes');
    expect(app(AppSettings::class)->isAppLockEnabled())->toBeFalse();
});

it('turns the lock back on with the PIN it already has', function () {
    app(AppLock::class)->setPin('2468');

    $response = $this->put(route('settings.security.update'), ($this->securitySettings)());

    $response->assertSessionHasNoErrors()->assertSessionHas('success', 'App lock saved.');
    expect(app(AppSettings::class)->isAppLockEnabled())->toBeTrue()
        ->and(app(AppLock::class)->pinMatches('2468'))->toBeTrue();
    $this->get(route('dashboard'))->assertOk();
});

it('turns the lock off with the current PIN', function () {
    ($this->lockedWithPin)('2468');

    $response = $this->put(route('settings.security.update'), ($this->securitySettings)([
        'lock_enabled' => false,
        'current_pin' => '2468',
    ]));

    $response->assertSessionHasNoErrors()->assertSessionHas('success', 'App lock turned off.');
    expect(app(AppSettings::class)->isAppLockEnabled())->toBeFalse();
});

it('needs the current PIN to turn the lock off or change the PIN', function (array $overrides) {
    ($this->lockedWithPin)('2468');

    $response = $this->put(route('settings.security.update'), ($this->securitySettings)($overrides));

    $response->assertSessionHasErrors('current_pin');
    expect(app(AppSettings::class)->isAppLockEnabled())->toBeTrue()
        ->and(app(AppLock::class)->pinMatches('2468'))->toBeTrue();
})->with([
    'turning it off' => [['lock_enabled' => false]],
    'changing the PIN' => [['pin' => '135790', 'pin_confirmation' => '135790']],
    'changing the PIN with the wrong current PIN' => [['pin' => '135790', 'pin_confirmation' => '135790', 'current_pin' => '9999']],
]);

it('changes the PIN when the current PIN is right', function () {
    ($this->lockedWithPin)('2468');

    $response = $this->put(route('settings.security.update'), ($this->securitySettings)([
        'current_pin' => '2468',
        'pin' => '135790',
        'pin_confirmation' => '135790',
    ]));

    $response->assertSessionHasNoErrors()->assertSessionHas('success', 'App lock saved.');
    expect(app(AppLock::class)->pinMatches('135790'))->toBeTrue()
        ->and(app(AppLock::class)->pinMatches('2468'))->toBeFalse();
});

it('saves the other lock options without asking for the PIN again', function () {
    ($this->lockedWithPin)('2468');

    $response = $this->put(route('settings.security.update'), ($this->securitySettings)([
        'use_biometrics' => true,
        'auto_lock_minutes' => 0,
        'lock_on_launch' => false,
    ]));

    $response->assertSessionHasNoErrors()->assertSessionHas('success', 'App lock saved.');
    $settings = app(AppSettings::class);
    expect($settings->unlocksWithBiometrics())->toBeTrue()
        ->and($settings->autoLockMinutes())->toBe(0)
        ->and($settings->locksOnLaunch())->toBeFalse()
        ->and($settings->isAppLockEnabled())->toBeTrue();
});
