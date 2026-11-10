<?php

namespace App\Http\Controllers;

use App\Models\GithubAccount;
use App\Services\AppLock;
use App\Services\AppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The lock screen: unlock with a PIN or Touch ID, or lock the app on demand.
 */
class LockController extends Controller
{
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
            'lockedOutSeconds' => $this->appLock->pinLockoutSeconds(),
            'recovery' => [
                'login' => GithubAccount::current()?->login,
                'token_url' => PinRecoveryController::tokenPageUrl(),
            ],
        ]);
    }

    public function unlockWithPin(Request $request): RedirectResponse
    {
        $validated = $request->validate(['pin' => ['required', 'string', 'max:12']]);

        if ($this->appLock->pinLockoutSeconds() > 0) {
            throw ValidationException::withMessages(['pin' => $this->appLock->pinLockoutMessage()]);
        }

        if (! $this->appLock->attemptPin($validated['pin'])) {
            throw ValidationException::withMessages([
                'pin' => $this->appLock->pinLockoutSeconds() > 0 ? $this->appLock->pinLockoutMessage() : 'That PIN is not right.',
            ]);
        }

        $this->appLock->unlock($request->session());

        return redirect()->intended(route('dashboard'));
    }

    public function unlockWithBiometrics(Request $request): RedirectResponse
    {
        if (! $this->appLock->verifyWithBiometrics()) {
            return back()->with('error', 'Touch ID did not unlock the app. Use your PIN instead.');
        }

        $this->appLock->forgetFailedPinAttempts();
        $this->appLock->unlock($request->session());

        return redirect()->intended(route('dashboard'));
    }

    public function lock(Request $request): RedirectResponse
    {
        $this->appLock->lock($request->session());
        Inertia::clearHistory();

        return redirect()->route('lock.show');
    }
}
