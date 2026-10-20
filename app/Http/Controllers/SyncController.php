<?php

namespace App\Http\Controllers;

use App\Jobs\PollNotifications;
use App\Jobs\SyncGithub;
use Illuminate\Http\RedirectResponse;

/**
 * Refreshes pull requests and notifications from GitHub on demand.
 */
class SyncController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        SyncGithub::dispatch();
        PollNotifications::dispatch();

        return back()->with('success', 'Syncing with GitHub…');
    }
}
