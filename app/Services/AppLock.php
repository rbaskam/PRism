<?php

namespace App\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Native\Desktop\Facades\System;
use Throwable;

/**
 * Locks the app behind a PIN and, where the device supports it, Touch ID.
 *
 * A session counts as unlocked for the current "lock generation". Starting the desktop app
 * (or choosing "Lock now") starts a new generation, so every window has to unlock again.
 */
class AppLock
{
    private const UNLOCKED_GENERATION = 'app_lock.unlocked_generation';

    private const LAST_ACTIVITY_AT = 'app_lock.last_activity_at';

    public function __construct(private readonly AppSettings $settings) {}

    public function isEnabled(): bool
    {
        return $this->settings->isAppLockEnabled();
    }

    public function isLocked(Session $session): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        if ($session->get(self::UNLOCKED_GENERATION) !== $this->generation()) {
            return true;
        }

        $autoLockMinutes = $this->settings->autoLockMinutes();
        $lastActivityAt = $session->get(self::LAST_ACTIVITY_AT);

        return $autoLockMinutes > 0 && $lastActivityAt !== null && now()->timestamp - $lastActivityAt >= $autoLockMinutes * 60;
    }

    public function unlock(Session $session): void
    {
        $session->migrate(true);
        $session->put(self::UNLOCKED_GENERATION, $this->generation());
        $this->recordActivity($session);
    }

    public function lock(Session $session): void
    {
        $session->forget([self::UNLOCKED_GENERATION, self::LAST_ACTIVITY_AT]);
    }

    /**
     * Lock every session, e.g. when the desktop app starts.
     */
    public function lockEverywhere(): void
    {
        $this->settings->set(AppSettings::APP_LOCK_GENERATION, Str::random(32));
    }

    public function recordActivity(Session $session): void
    {
        $session->put(self::LAST_ACTIVITY_AT, now()->timestamp);
    }

    public function pinMatches(string $pin): bool
    {
        $hash = $this->settings->get(AppSettings::APP_LOCK_PIN_HASH);

        return is_string($hash) && Hash::check($pin, $hash);
    }

    public function setPin(string $pin): void
    {
        $this->settings->set(AppSettings::APP_LOCK_PIN_HASH, Hash::make($pin));
    }

    /**
     * Forget the PIN and turn the lock off, for someone who forgot their PIN and proved the app is theirs.
     */
    public function removePin(): void
    {
        $this->settings->setMany([
            AppSettings::APP_LOCK_PIN_HASH => null,
            AppSettings::APP_LOCK_ENABLED => false,
        ]);
    }

    /**
     * Whether this device can unlock with biometrics. Touch ID on macOS today; Face ID would come from a mobile build.
     */
    public function biometricsAvailable(): bool
    {
        if (! config('nativephp-internal.running') || PHP_OS_FAMILY !== 'Darwin') {
            return false;
        }

        try {
            return System::canPromptTouchID();
        } catch (Throwable) {
            return false;
        }
    }

    public function biometricsLabel(): ?string
    {
        return PHP_OS_FAMILY === 'Darwin' ? 'Touch ID' : null;
    }

    /**
     * Ask the operating system to verify the user's fingerprint. Blocks until the prompt is answered.
     */
    public function verifyWithBiometrics(): bool
    {
        if (! $this->settings->unlocksWithBiometrics() || ! $this->biometricsAvailable()) {
            return false;
        }

        try {
            return System::promptTouchID('unlock '.config('app.name'));
        } catch (Throwable) {
            return false;
        }
    }

    private function generation(): string
    {
        return (string) $this->settings->get(AppSettings::APP_LOCK_GENERATION, 'initial');
    }
}
