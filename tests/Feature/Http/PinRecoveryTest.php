<?php

use App\Models\GithubAccount;
use App\Models\Setting;
use App\Services\AppLock;
use App\Services\AppSettings;
use App\Services\Github\GithubClient;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    app(AppLock::class)->setPin('2468');
    app(AppSettings::class)->set(AppSettings::APP_LOCK_ENABLED, true);
});

/**
 * Answer GitHub's /user with this login, or with 401 Bad credentials when it is null.
 */
function fakeGithubUser(?string $login): void
{
    Http::fake([GithubClient::BASE_URL.'/user' => $login === null
        ? Http::response(['message' => 'Bad credentials'], 401)
        : Http::response(['id' => 583231, 'login' => $login], 200, ['X-OAuth-Scopes' => '']),
    ]);
}

it('shows which GitHub account a recovery token must belong to', function () {
    onboardedAccount(['login' => 'octocat']);

    $response = $this->get(route('lock.show'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Lock')
        ->where('recovery.login', 'octocat'));
});

it('removes the PIN, turns the lock off and unlocks with a token for the connected account', function () {
    $account = onboardedAccount(['login' => 'Octocat', 'token' => 'ghp_connected']);
    fakeGithubUser('octocat');

    $response = $this->post(route('lock.recovery'), ['token' => 'ghp_test']);

    $response->assertRedirect(route('settings.security'))
        ->assertSessionHas('success', 'Your PIN was removed and the app lock is off. Set a new PIN to turn it back on.');
    $settings = app(AppSettings::class);
    expect($settings->get(AppSettings::APP_LOCK_PIN_HASH))->toBeNull()
        ->and($settings->isAppLockEnabled())->toBeFalse()
        ->and($account->fresh()->token)->toBe('ghp_connected');
    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer ghp_test'));
    $this->get(route('dashboard'))->assertOk();
});

it('stays locked when the token belongs to someone else', function () {
    onboardedAccount(['login' => 'octocat']);
    fakeGithubUser('hubot');

    $response = $this->post(route('lock.recovery'), ['token' => 'ghp_test']);

    $response->assertSessionHasErrors(['token' => "That isn't a working token for @octocat. Check you copied all of it, and that you created it while signed in to GitHub as @octocat."]);
    expect(app(AppLock::class)->pinMatches('2468'))->toBeTrue()
        ->and(app(AppSettings::class)->isAppLockEnabled())->toBeTrue();
    $this->get(route('dashboard'))->assertRedirect(route('lock.show'));
});

it('stays locked when GitHub rejects the token', function () {
    onboardedAccount(['login' => 'octocat']);
    fakeGithubUser(null);

    $response = $this->post(route('lock.recovery'), ['token' => 'ghp_test']);

    expect(session('errors')->first('token'))->toStartWith("That isn't a working token for @octocat.");
    expect(app(AppLock::class)->pinMatches('2468'))->toBeTrue();
    $this->get(route('dashboard'))->assertRedirect(route('lock.show'));
});

it('stops checking tokens after five failed attempts', function () {
    onboardedAccount(['login' => 'octocat']);
    fakeGithubUser('hubot');

    foreach (range(1, 5) as $attempt) {
        $this->post(route('lock.recovery'), ['token' => 'ghp_test']);
    }
    $this->post(route('lock.recovery'), ['token' => 'ghp_test']);

    expect(session('errors')->first('token'))->toStartWith('Too many attempts. Try again in');
    Http::assertSentCount(5);
    $this->get(route('dashboard'))->assertRedirect(route('lock.show'));
});

it('explains that the PIN cannot be recovered without a connected GitHub account', function () {
    app(AppSettings::class)->set(AppSettings::ONBOARDING_COMPLETED_AT, now()->toIso8601String());

    $response = $this->post(route('lock.recovery'), ['token' => 'ghp_test']);

    expect(session('errors')->first('token'))->toContain("the PIN can't be recovered");
    Http::assertNothingSent();
});

it('never stores the token it was given', function (?string $githubLogin) {
    $account = onboardedAccount(['login' => 'octocat', 'token' => 'ghp_connected']);
    fakeGithubUser($githubLogin);

    $this->post(route('lock.recovery'), ['token' => 'ghp_test_recovery']);

    expect(serialize(session()->all()))->not->toContain('ghp_test_recovery')
        ->and(Setting::query()->pluck('value')->implode(' '))->not->toContain('ghp_test_recovery')
        ->and(GithubAccount::query()->sole()->is($account))->toBeTrue()
        ->and($account->fresh()->token)->toBe('ghp_connected');
})->with([
    'accepted' => 'octocat',
    'someone else' => 'hubot',
    'rejected' => [null],
]);
