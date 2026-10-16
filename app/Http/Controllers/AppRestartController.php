<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Native\Desktop\Facades\App as NativeApp;

/**
 * Restarts the desktop app, from the "PRism's server stopped" message. The relaunch runs once this response has been
 * sent. It sits outside the app lock so the lock screen can use it too; the app locks again when it starts.
 */
class AppRestartController extends Controller
{
    public function __invoke(): JsonResponse
    {
        if (! config('nativephp-internal.running')) {
            return response()->json(['restarting' => false]);
        }

        defer(fn () => NativeApp::relaunch());

        return response()->json(['restarting' => true]);
    }
}
