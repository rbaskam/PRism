<?php

namespace App\Services;

use App\Enums\NotificationSound;
use Native\Desktop\Facades\Dock;
use Native\Desktop\Facades\Notification;
use Throwable;

/**
 * Sends native desktop notifications when the app runs inside NativePHP, and does nothing otherwise
 * (for example under `php artisan serve` or in tests).
 */
class DesktopNotifier
{
    public const PULL_REQUEST_REFERENCE = 'pull-request:';

    public const GITHUB_NOTIFICATION_REFERENCE = 'github-notification:';

    public function __construct(private readonly AppSettings $settings) {}

    public function isAvailable(): bool
    {
        return (bool) config('nativephp-internal.running');
    }

    /**
     * Show a notification with the sound chosen in Settings → Notifications, unless another sound is given.
     * The reference is echoed back when the user clicks it.
     */
    public function send(string $title, string $message, ?string $reference = null, ?NotificationSound $sound = null): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        $sound ??= $this->settings->notificationSound();

        try {
            $notification = Notification::new()->title($title)->message($message);

            if ($reference !== null) {
                $notification->reference($reference);
            }

            if ($sound === NotificationSound::None) {
                $notification->silent();
            }

            if ($sound->systemSoundName() !== null) {
                $notification->sound($sound->systemSoundName());
            }

            $notification->show();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Show the number of pull requests awaiting review on the macOS Dock icon.
     */
    public function setBadgeCount(int $count): void
    {
        if (! $this->isAvailable() || PHP_OS_FAMILY !== 'Darwin') {
            return;
        }

        try {
            Dock::badge($count > 0 ? (string) $count : '');
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
