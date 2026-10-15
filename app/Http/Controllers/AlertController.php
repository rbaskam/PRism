<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use Illuminate\Http\JsonResponse;

/**
 * The alerts listed under the bell in the header. These answer with JSON, so opening the list or marking alerts as
 * read never reloads the page the user is on.
 */
class AlertController extends Controller
{
    /**
     * How many of the latest alerts the list shows.
     */
    public const LIMIT = 50;

    public function index(): JsonResponse
    {
        $alerts = Alert::query()->latest('id')->limit(self::LIMIT)->get();

        return response()->json([
            'alerts' => $alerts->map(fn (Alert $alert): array => [
                'id' => $alert->id,
                'type' => $alert->type->value,
                'title' => $alert->title,
                'body' => $alert->body,
                'url' => $alert->url,
                'is_read' => $alert->read_at !== null,
                'created_at' => $alert->created_at?->toIso8601String(),
            ])->all(),
            'unread' => Alert::query()->unread()->count(),
        ]);
    }

    public function markAsRead(Alert $alert): JsonResponse
    {
        $alert->read_at ??= now();
        $alert->save();

        return response()->json(['unread' => Alert::query()->unread()->count()]);
    }

    public function markAllAsRead(): JsonResponse
    {
        Alert::query()->unread()->update(['read_at' => now()]);

        return response()->json(['unread' => 0]);
    }
}
