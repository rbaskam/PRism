<?php

namespace App\Jobs;

use App\Services\AppSettings;
use App\Services\DesktopNotifier;
use App\Services\UpdateChecker;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Looks for a newer PRism release on GitHub and lets the user know once per new version.
 */
class CheckForUpdates implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const NOTIFIED_VERSION = 'updates.notified_version';

    public int $uniqueFor = 300;

    public function handle(UpdateChecker $updates, DesktopNotifier $notifier, AppSettings $settings): void
    {
        $latest = $updates->check();

        if ($latest === null || ! $updates->updateIsAvailable()) {
            return;
        }

        if ($settings->get(self::NOTIFIED_VERSION) === $latest['version']) {
            return;
        }

        $notifier->send(config('app.name').' '.$latest['version'].' is available', 'Open Settings → About to download it.');
        $settings->set(self::NOTIFIED_VERSION, $latest['version']);
    }
}
