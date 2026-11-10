<?php

namespace App\Http\Controllers;

use App\Actions\LinkLocalCheckouts;
use App\Rules\LocalFolder;
use App\Services\AppSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Scans a folder on this computer for checkouts of tracked repositories.
 */
class LocalCheckoutScanController extends Controller
{
    public function __invoke(Request $request, LinkLocalCheckouts $link, AppSettings $settings): JsonResponse
    {
        $validated = $request->validate([
            'folder' => ['bail', 'required', 'string', 'max:1024', new LocalFolder],
        ]);

        $settings->set(AppSettings::LOCAL_CODE_FOLDER, $validated['folder']);

        return response()->json($link->handle($validated['folder']));
    }
}
