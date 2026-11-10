<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\AppLock;
use App\Services\AppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * App lock settings: a PIN, optional Touch ID, auto-lock after inactivity and locking when the app starts.
 */
class SecuritySettingsController extends Controller
{
    public const AUTO_LOCK_OPTIONS = [0, 1, 5, 15, 30, 60];

    public function edit(AppSettings $settings, AppLock $appLock): Response
    {
        return Inertia::render('settings/Security', [
            'settings' => [
                'lock_enabled' => $settings->isAppLockEnabled(),
                'has_pin' => $settings->get(AppSettings::APP_LOCK_PIN_HASH) !== null,
                'use_biometrics' => $settings->unlocksWithBiometrics(),
                'auto_lock_minutes' => $settings->autoLockMinutes(),
                'lock_on_launch' => $settings->locksOnLaunch(),
            ],
            'biometrics' => [
                'available' => $appLock->biometricsAvailable(),
                'label' => $appLock->biometricsLabel(),
            ],
            'autoLockOptions' => self::AUTO_LOCK_OPTIONS,
        ]);
    }

    public function update(Request $request, AppSettings $settings, AppLock $appLock): RedirectResponse
    {
        $wasEnabled = $settings->isAppLockEnabled();

        $validated = $request->validate([
            'lock_enabled' => ['required', 'boolean'],
            'pin' => [
                Rule::requiredIf(fn (): bool => $request->boolean('lock_enabled') && $settings->get(AppSettings::APP_LOCK_PIN_HASH) === null),
                'nullable',
                'digits_between:4,8',
                'confirmed',
            ],
            'current_pin' => [
                Rule::requiredIf(fn (): bool => $wasEnabled && $this->weakensLock($request, $settings)),
                'nullable',
                'string',
                'max:12',
            ],
            'use_biometrics' => ['required', 'boolean'],
            'auto_lock_minutes' => ['required', 'integer', Rule::in(self::AUTO_LOCK_OPTIONS)],
            'lock_on_launch' => ['required', 'boolean'],
        ], [
            'current_pin.required' => 'Enter your current PIN to save these changes.',
        ]);

        if (filled($validated['current_pin'] ?? null)) {
            $this->checkCurrentPin($appLock, $validated['current_pin']);
        }

        if (filled($validated['pin'] ?? null)) {
            $appLock->setPin($validated['pin']);
        }

        $settings->setMany([
            AppSettings::APP_LOCK_ENABLED => $validated['lock_enabled'],
            AppSettings::APP_LOCK_USE_BIOMETRICS => $validated['use_biometrics'],
            AppSettings::APP_LOCK_AUTO_LOCK_MINUTES => $validated['auto_lock_minutes'],
            AppSettings::APP_LOCK_ON_LAUNCH => $validated['lock_on_launch'],
        ]);

        if ($validated['lock_enabled'] && ! $wasEnabled) {
            $appLock->unlock($request->session());
        }

        return back()->with('success', $validated['lock_enabled'] ? 'App lock saved.' : 'App lock turned off.');
    }

    /**
     * Whether the request makes the lock easier to get past: turning it off, changing the PIN, unlocking with a
     * fingerprint (any finger enrolled on the Mac), locking later or never, or not locking when the app starts. Someone
     * at an unlocked app has to know the PIN to do that.
     */
    private function weakensLock(Request $request, AppSettings $settings): bool
    {
        return ! $request->boolean('lock_enabled')
            || filled($request->input('pin'))
            || ($request->boolean('use_biometrics') && ! $settings->unlocksWithBiometrics())
            || (! $request->boolean('lock_on_launch') && $settings->locksOnLaunch())
            || $this->autoLocksAfter($request->integer('auto_lock_minutes')) > $this->autoLocksAfter($settings->autoLockMinutes());
    }

    /**
     * Minutes of inactivity before the app locks itself, where the setting's 0 (never) is the longest.
     */
    private function autoLocksAfter(int $minutes): int
    {
        return $minutes === 0 ? PHP_INT_MAX : $minutes;
    }

    /**
     * Wrong PINs here count towards the same lockout as on the lock screen, so this page can't be used to guess it.
     *
     * @throws ValidationException
     */
    private function checkCurrentPin(AppLock $appLock, #[\SensitiveParameter] string $pin): void
    {
        if ($appLock->pinLockoutSeconds() > 0) {
            throw ValidationException::withMessages(['current_pin' => $appLock->pinLockoutMessage()]);
        }

        if (! $appLock->attemptPin($pin)) {
            throw ValidationException::withMessages([
                'current_pin' => $appLock->pinLockoutSeconds() > 0 ? $appLock->pinLockoutMessage() : 'Your current PIN is not right.',
            ]);
        }
    }
}
