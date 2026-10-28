<?php

namespace App\Http\Controllers;

use App\Models\GithubAccount;
use App\Services\AppLock;
use App\Services\AppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The lock screen: unlock with a PIN or Touch ID, or lock the app on demand.
 */
class LockController extends Controller
{
    public const PIN_THROTTLE_KEY = 'app-lock-pin';

    private const MAX_PIN_ATTEMPTS = 5;

    public function __construct(private readonly AppLock $appLock) {}

    public function show(Request $request, AppSettings $settings): Response|RedirectResponse
    {
        if (! $this->appLock->isLocked($request->session())) {
            return redirect()->intended(route('dashboard'));
        }

        return Inertia::render('Lock', [
            'biometrics' => [
                'enabled' => $settings->unlocksWithBiometrics() && $this->appLock->biometricsAvailable(),
                'label' => $this->appLock->biometricsLabel(),
            ],
            'lockedOutSeconds' => RateLimiter::availableIn($this->throttleKey()) * (int) RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_PIN_ATTEMPTS),
            'recovery' => [
                'login' => GithubAccount::current()?->login,
                'token_url' => PinRecoveryController::tokenPageUrl(),
            ],
        ]);
    }

    public function unlockWithPin(Request $request): RedirectResponse
    {
        $validated = $request->validate(['pin' => ['required', 'string', 'max:12']]);

        if (RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_PIN_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'pin' => 'Too many attempts. Try again in '.RateLimiter::availableIn($this->throttleKey()).' seconds.',
            ]);
        }

        if (! $this->appLock->pinMatches($validated['pin'])) {
            RateLimiter::hit($this->throttleKey(), 300);

            throw ValidationException::withMessages(['pin' => 'That PIN is not right.']);
        }

        RateLimiter::clear($this->throttleKey());
        $this->appLock->unlock($request->session());

        return redirect()->intended(route('dashboard'));
    }

    public function unlockWithBiometrics(Request $request): RedirectResponse
    {
        if (! $this->appLock->verifyWithBiometrics()) {
            return back()->with('error', 'Touch ID did not unlock the app. Use your PIN instead.');
        }

        RateLimiter::clear($this->throttleKey());
        $this->appLock->unlock($request->session());

        return redirect()->intended(route('dashboard'));
    }

    public function lock(Request $request): RedirectResponse
    {
        $this->appLock->lock($request->session());

        return redirect()->route('lock.show');
    }

    private function throttleKey(): string
    {
        return self::PIN_THROTTLE_KEY;
    }
}
