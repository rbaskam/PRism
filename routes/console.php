<?php

use App\Actions\RestartAppIfServerStopped;
use App\Jobs\CheckForUpdates;
use App\Jobs\PollNotifications;
use App\Jobs\SyncGithub;
use App\Models\Alert;
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

// NativePHP doesn't restart the app's web server if it stops, so relaunch the app when it has.
// The callback returns nothing: the scheduler counts a callback that returns false as failed and logs an error.
Schedule::call(function (): void {
    app(RestartAppIfServerStopped::class)->handle();
})
    ->everyMinute()
    ->name('restart-app-if-server-stopped')
    ->when(fn (): bool => (bool) config('nativephp-internal.running'));
Schedule::command('model:prune', ['--model' => [Alert::class]])->daily();
