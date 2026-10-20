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
                Rule::requiredIf(fn (): bool => $wasEnabled && (! $request->boolean('lock_enabled') || filled($request->input('pin')))),
                'nullable',
                'string',
            ],
            'use_biometrics' => ['required', 'boolean'],
            'auto_lock_minutes' => ['required', 'integer', Rule::in(self::AUTO_LOCK_OPTIONS)],
            'lock_on_launch' => ['required', 'boolean'],
        ]);

        if (filled($validated['current_pin'] ?? null) && ! $appLock->pinMatches($validated['current_pin'])) {
            throw ValidationException::withMessages(['current_pin' => 'Your current PIN is not right.']);
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
}
