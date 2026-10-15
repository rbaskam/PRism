<?php

use App\Jobs\CheckForUpdates;
use App\Jobs\PollNotifications;
use App\Jobs\SyncGithub;
use App\Models\GithubAccount;
use Illuminate\Support\Facades\Schedule;

/*
 * NativePHP runs `schedule:run` every minute and stops a run after 60 seconds,
 * so scheduled work only dispatches jobs for the queue workers.
 */
$githubIsConnected = fn (): bool => GithubAccount::query()->exists();

Schedule::job(new PollNotifications)->everyMinute()->when($githubIsConnected);
Schedule::job(new SyncGithub)->everyFiveMinutes()->when($githubIsConnected);
Schedule::job(new CheckForUpdates)->everySixHours();
