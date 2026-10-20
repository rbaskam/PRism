<?php

namespace App\Http\Controllers;

use App\Actions\LinkLocalCheckouts;
use App\Services\AppSettings;
use Closure;
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
            'folder' => ['required', 'string', 'max:1024', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_dir($value)) {
                    $fail('That folder does not exist on this computer.');
                }
            }],
        ]);

        $settings->set(AppSettings::LOCAL_CODE_FOLDER, $validated['folder']);

        return response()->json($link->handle($validated['folder']));
    }
}
