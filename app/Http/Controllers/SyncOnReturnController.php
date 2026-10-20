<?php

namespace App\Http\Controllers;

use App\Jobs\SyncGithub;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Syncs with GitHub when the user comes back to PRism, e.g. after approving a pull request on github.com, so the
 * change shows straight away rather than at the next scheduled sync. Switching back and forth only syncs again after
 * a short while.
 */
class SyncOnReturnController extends Controller
{
    public const SECONDS_BETWEEN_SYNCS = 30;

    public function __invoke(): JsonResponse
    {
        $isSyncing = Cache::add('github.synced-on-return', true, self::SECONDS_BETWEEN_SYNCS);

        if ($isSyncing) {
            SyncGithub::dispatch();
        }

        return response()->json(['syncing' => $isSyncing]);
    }
}
