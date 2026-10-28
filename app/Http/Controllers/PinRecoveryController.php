<?php

namespace App\Http\Controllers;

use App\Models\GithubAccount;
use App\Services\AppLock;
use App\Services\Github\GithubClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Native\Desktop\Facades\Shell;

/**
 * "Forgot your PIN?" on the lock screen. Whoever controls the connected GitHub account can prove it with a personal
 * access token, which removes the PIN and turns the lock off so they can set a new one.
 *
 * The token is only used to ask GitHub who it belongs to: it is never stored, logged or sent back, and the app's own
 * GitHub token is left alone.
 */
class PinRecoveryController extends Controller
{
    private const THROTTLE_KEY = 'app-lock-recovery';

    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function __construct(private readonly AppLock $appLock) {}

    /**
     * GitHub's page for creating a personal access token. The token needs no scopes to identify its owner.
     */
    public static function tokenPageUrl(): string
    {
        return 'https://github.com/settings/tokens/new?'.http_build_query(['description' => config('app.name').' PIN reset'], encoding_type: PHP_QUERY_RFC3986);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $this->appLock->isLocked($request->session())) {
            return redirect()->intended(route('dashboard'));
        }

        $account = GithubAccount::current();

        if ($account === null) {
            throw ValidationException::withMessages([
                'token' => "No GitHub account is connected, so there's no way to prove the app is yours and the PIN can't be recovered.",
            ]);
        }

        $validated = $request->validate(['token' => ['required', 'string', 'max:255']]);

        if (RateLimiter::tooManyAttempts(self::THROTTLE_KEY, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'token' => 'Too many attempts. Try again in '.RateLimiter::availableIn(self::THROTTLE_KEY).' seconds.',
            ]);
        }

        if (! $this->tokenBelongsTo(trim($validated['token']), $account)) {
            RateLimiter::hit(self::THROTTLE_KEY, self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'token' => "That isn't a working token for @{$account->login}. Check you copied all of it, and that you created it while signed in to GitHub as @{$account->login}.",
            ]);
        }

        RateLimiter::clear(self::THROTTLE_KEY);
        RateLimiter::clear(LockController::PIN_THROTTLE_KEY);
        $this->appLock->removePin();
        $this->appLock->unlock($request->session());

        return redirect()->route('settings.security')
            ->with('success', 'Your PIN was removed and the app lock is off. Set a new PIN to turn it back on.');
    }

    /**
     * Open GitHub's token page in the system browser. The lock screen can't use the external links route, which only
     * works while the app is unlocked, so this route opens just this one page.
     */
    public function openTokenPage(): JsonResponse
    {
        $isNative = (bool) config('nativephp-internal.running');

        if ($isNative) {
            Shell::openExternal(self::tokenPageUrl());
        }

        return response()->json(['opened' => $isNative]);
    }

    /**
     * Ask GitHub who the token belongs to. Problems reaching GitHub are reported without counting as a failed attempt.
     *
     * @throws ValidationException
     */
    private function tokenBelongsTo(string $token, GithubAccount $account): bool
    {
        try {
            $login = (new GithubClient($token))->authenticatedUser()['user']['login'] ?? null;
        } catch (RequestException $exception) {
            if ($exception->response->unauthorized()) {
                return false;
            }

            throw ValidationException::withMessages([
                'token' => "GitHub couldn't check that token right now (error {$exception->response->status()}). Try again in a moment.",
            ]);
        } catch (ConnectionException) {
            throw ValidationException::withMessages([
                'token' => 'Could not reach GitHub. Check your internet connection and try again.',
            ]);
        }

        return is_string($login) && strcasecmp($login, $account->login) === 0;
    }
}
