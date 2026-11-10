<?php

use App\Enums\AiTaskStatus;
use App\Models\PullRequest;
use App\Models\ReviewRun;
use App\Services\AppLock;
use App\Services\AppSettings;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

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

it('locks PIN entry for longer each time five more wrong PINs are entered', function () {
    onboardedAccount();
    enableAppLock('2468');
    $enterWrongPins = function (): void {
        foreach (range(1, AppLock::PIN_ATTEMPTS_BEFORE_LOCKOUT) as $attempt) {
            $this->post(route('lock.pin'), ['pin' => '0000']);
        }
    };

    $enterWrongPins();
    expect(session('errors')->first('pin'))->toBe('Too many attempts. Try again in 5 minutes.');
    $this->travel(5)->minutes();
    $enterWrongPins();

    expect(session('errors')->first('pin'))->toBe('Too many attempts. Try again in 15 minutes.');
    $this->get(route('lock.show'))->assertInertia(fn (Assert $page) => $page->where('lockedOutSeconds', 900));
    $this->travel(14)->minutes();
    $this->post(route('lock.pin'), ['pin' => '2468'])->assertSessionHasErrors(['pin' => 'Too many attempts. Try again in 1 minute.']);
    $this->get(route('dashboard'))->assertRedirect(route('lock.show'));
});

it('starts counting wrong PINs again after the right one', function () {
    onboardedAccount();
    enableAppLock('2468');
    foreach (range(1, AppLock::PIN_ATTEMPTS_BEFORE_LOCKOUT - 1) as $attempt) {
        $this->post(route('lock.pin'), ['pin' => '0000']);
    }
    $this->post(route('lock.pin'), ['pin' => '2468']);
    $this->post(route('lock.store'));

    $response = $this->post(route('lock.pin'), ['pin' => '0000']);

    $response->assertSessionHasErrors(['pin' => 'That PIN is not right.']);
    expect(app(AppLock::class)->pinLockoutSeconds())->toBe(0);
});

it('locks again after the auto-lock time without activity', function () {
    onboardedAccount();
    enableAppLock('2468', autoLockMinutes: 5);
    $this->post(route('lock.pin'), ['pin' => '2468']);

    $this->travel(6)->minutes();

    $this->get(route('dashboard'))->assertRedirect(route('lock.show'));
});

it('counts clicks and typing in the app as activity, so it stays unlocked while in use', function () {
    onboardedAccount();
    enableAppLock('2468', autoLockMinutes: 5);
    $this->post(route('lock.pin'), ['pin' => '2468']);
    $this->travel(4)->minutes();

    $this->postJson(route('activity.store'))->assertNoContent();
    $this->travel(4)->minutes();

    $this->get(route('dashboard'))->assertOk();
});

it('does not unlock the app when it hears about activity while locked', function () {
    onboardedAccount();
    enableAppLock();

    $this->postJson(route('activity.store'))->assertStatus(423);

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

it('tells the window to forget the pages it showed before the app locked', function (Closure $lock) {
    onboardedAccount();
    enableAppLock('2468');
    $this->post(route('lock.pin'), ['pin' => '2468']);

    $lock($this);

    $page = $this->get(route('lock.show'))->inertiaPage();
    $pageAgain = $this->get(route('lock.show'))->inertiaPage();
    expect($page['clearHistory'])->toBeTrue()
        ->and($page['encryptHistory'])->toBeTrue()
        // Only once: going back reloads the lock screen, which then has to replace the page it was reloaded for.
        ->and($pageAgain['clearHistory'])->toBeFalse();
})->with([
    'locking now' => [fn ($test) => $test->post(route('lock.store'))->assertRedirect(route('lock.show'))],
    'auto-locking' => [function ($test) {
        $test->travel(16)->minutes();
        $test->get(route('dashboard'))->assertRedirect(route('lock.show'));
    }],
]);

it('shows the lock screen nothing about the account or its pull requests', function () {
    onboardedAccount(['login' => 'octocat', 'name' => 'The Octocat']);
    enableAppLock('2468');
    $pullRequest = PullRequest::factory()->create(['title' => 'Add rate limiting', 'last_viewed_at' => now()]);
    ReviewRun::factory()->for($pullRequest)->create(['status' => AiTaskStatus::Running, 'started_at' => now()]);

    $response = $this->get(route('lock.show'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Lock')
        ->where('appLock.enabled', true)
        ->has('name')
        ->has('isNative')
        ->missing('github')
        ->missing('counts')
        ->missing('recentPullRequests')
        ->missing('whatsNew'));
    expect($response->getContent())->not->toContain('Add rate limiting')
        ->not->toContain('The Octocat');
});

it('shares the account and its pull requests again once unlocked', function () {
    onboardedAccount(['login' => 'octocat']);
    enableAppLock('2468');
    $this->post(route('lock.pin'), ['pin' => '2468']);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('github.login', 'octocat')
        ->has('counts')
        ->has('recentPullRequests'));
});
