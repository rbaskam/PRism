<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Native\Desktop\Dialog;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the user pick a local checkout folder with the native "Choose folder" dialog.
 */
class LocalFolderController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(config('nativephp-internal.running'), Response::HTTP_NOT_FOUND);

        $validated = $request->validate(['title' => ['nullable', 'string', 'max:120']]);

        $path = Dialog::new()
            ->title($validated['title'] ?? 'Choose the local checkout of this repository')
            ->button('Use this folder')
            ->folders()
            ->open();

        return response()->json(['path' => is_string($path) ? $path : null]);
    }
}
