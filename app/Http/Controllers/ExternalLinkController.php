<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Native\Desktop\Facades\Shell;

/**
 * Opens a link in the system browser when running as a desktop app.
 */
class ExternalLinkController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate(['url' => ['required', 'url:https', 'max:2048']]);

        if (config('nativephp-internal.running')) {
            Shell::openExternal($validated['url']);
        }

        return back();
    }
}
