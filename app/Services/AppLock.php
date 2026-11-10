<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
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

    /**
     * Wrong PINs in a row, counted across the lock screen and Settings → Security, in every window.
     */
    private const FAILED_PIN_ATTEMPTS = 'app-lock-pin.failures';

    private const PIN_LOCKED_OUT_UNTIL = 'app-lock-pin.locked-out-until';

    /**
     * Every this many wrong PINs in a row, PIN entry is blocked for a while.
     */
    public const PIN_ATTEMPTS_BEFORE_LOCKOUT = 5;

    /**
     * How long each lockout lasts: 5 minutes the first time, then longer each time, up to a day.
     */
    public const PIN_LOCKOUT_SECONDS = [300, 900, 3600, 14400, 86400];

    /**
     * Wrong PINs are forgotten after a week without another one, or as soon as the right PIN is entered.
     */
    private const FAILED_PIN_ATTEMPTS_REMEMBERED_FOR_DAYS = 7;

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

    /**
     * Whether the session was unlocked at some point and hasn't been locked since, even if it now counts as locked
     * because the app restarted or it was left idle.
     */
    public function wasUnlocked(Session $session): bool
    {
        return $session->has(self::UNLOCKED_GENERATION);
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

    /**
     * Check a PIN the user typed. A wrong one counts towards a lockout that gets longer each time it's reached, so a
     * short PIN can't be guessed by trying them all; the right one resets the count. Check pinLockoutSeconds() first.
     */
    public function attemptPin(#[\SensitiveParameter] string $pin): bool
    {
        if ($this->pinMatches($pin)) {
            $this->forgetFailedPinAttempts();

            return true;
        }

        $failures = (int) Cache::get(self::FAILED_PIN_ATTEMPTS, 0) + 1;
        Cache::put(self::FAILED_PIN_ATTEMPTS, $failures, now()->addDays(self::FAILED_PIN_ATTEMPTS_REMEMBERED_FOR_DAYS));

        if ($failures % self::PIN_ATTEMPTS_BEFORE_LOCKOUT === 0) {
            $lockouts = intdiv($failures, self::PIN_ATTEMPTS_BEFORE_LOCKOUT);
            $seconds = self::PIN_LOCKOUT_SECONDS[min($lockouts, count(self::PIN_LOCKOUT_SECONDS)) - 1];
            Cache::put(self::PIN_LOCKED_OUT_UNTIL, now()->addSeconds($seconds)->getTimestamp(), now()->addSeconds($seconds));
        }

        return false;
    }

    /**
     * How many seconds are left before a PIN can be tried again after too many wrong ones, or 0.
     *
     * @phpstan-impure
     */
    public function pinLockoutSeconds(): int
    {
        $lockedOutUntil = Cache::get(self::PIN_LOCKED_OUT_UNTIL);

        return is_int($lockedOutUntil) ? max(0, $lockedOutUntil - now()->getTimestamp()) : 0;
    }

    /**
     * What to tell someone who is locked out, e.g. "Too many attempts. Try again in 5 minutes."
     */
    public function pinLockoutMessage(): string
    {
        $wait = CarbonInterval::seconds(max(1, $this->pinLockoutSeconds()))->cascade()->forHumans(['parts' => 1, 'options' => CarbonInterface::CEIL]);

        return "Too many attempts. Try again in {$wait}.";
    }

    public function forgetFailedPinAttempts(): void
    {
        Cache::forget(self::FAILED_PIN_ATTEMPTS);
        Cache::forget(self::PIN_LOCKED_OUT_UNTIL);
    }

    public function pinMatches(#[\SensitiveParameter] string $pin): bool
    {
        $hash = $this->settings->get(AppSettings::APP_LOCK_PIN_HASH);

        return is_string($hash) && Hash::check($pin, $hash);
    }

    public function setPin(#[\SensitiveParameter] string $pin): void
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
