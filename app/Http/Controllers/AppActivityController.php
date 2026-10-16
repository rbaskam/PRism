<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * The app's "still here" signal, sent while the user clicks, types or scrolls. EnsureAppIsUnlocked records every
 * request that isn't a background poll as activity, so this only needs to answer; while the app is locked the
 * middleware answers instead, so the signal can never unlock it.
 */
class AppActivityController extends Controller
{
    public function __invoke(): Response
    {
        return response()->noContent();
    }
}
