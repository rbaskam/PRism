<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Native\Desktop\Facades\Shell;

/**
 * Opens a link in the system browser when running as a desktop app. It answers with JSON rather than redirecting,
 * so opening a link never reloads the page the user is on.
 */
class ExternalLinkController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate(['url' => ['required', 'url:https', 'max:2048']]);

        if (config('nativephp-internal.running')) {
            Shell::openExternal($validated['url']);
        }

        return response()->json(['opened' => (bool) config('nativephp-internal.running')]);
    }
}
