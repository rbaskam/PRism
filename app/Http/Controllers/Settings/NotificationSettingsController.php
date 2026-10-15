<?php

namespace App\Http\Controllers\Settings;

use App\Enums\AlertType;
use App\Enums\NotificationSound;
use App\Http\Controllers\Controller;
use App\Services\AppSettings;
use App\Services\DesktopNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class NotificationSettingsController extends Controller
{
    public function edit(AppSettings $settings, DesktopNotifier $notifier): Response
    {
        return Inertia::render('settings/Notifications', [
            'settings' => [
                'desktop' => $settings->sendsDesktopNotifications(),
                ...collect(AlertType::cases())->mapWithKeys(fn (AlertType $type): array => [
                    $type->value => (bool) $settings->get('notifications.'.$type->value, $type->isOnByDefault()),
                ])->all(),
                'follow_up_activity' => $settings->alertsOnFollowUpActivity(),
                'sound' => $settings->notificationSound()->value,
            ],
            'sounds' => collect(NotificationSound::availableOn(PHP_OS_FAMILY))
                ->map(fn (NotificationSound $sound): array => ['value' => $sound->value, 'label' => $sound->label()])
                ->all(),
            'desktopAvailable' => $notifier->isAvailable(),
        ]);
    }

    public function update(Request $request, AppSettings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'desktop' => ['required', 'boolean'],
            'follow_up_activity' => ['required', 'boolean'],
            'sound' => ['required', Rule::enum(NotificationSound::class)],
            ...collect(AlertType::cases())->mapWithKeys(fn (AlertType $type): array => [$type->value => ['required', 'boolean']])->all(),
        ]);

        $settings->setMany([
            AppSettings::NOTIFY_DESKTOP => $validated['desktop'],
            AppSettings::NOTIFY_FOLLOW_UP_ACTIVITY => $validated['follow_up_activity'],
            AppSettings::NOTIFY_SOUND => $validated['sound'],
            ...collect(AlertType::cases())->mapWithKeys(fn (AlertType $type): array => [
                'notifications.'.$type->value => $validated[$type->value],
            ])->all(),
        ]);

        return back()->with('success', 'Notification settings saved.');
    }

    /**
     * Send a test notification, playing the sound picked on the page even before it is saved.
     */
    public function test(Request $request, DesktopNotifier $notifier): RedirectResponse
    {
        $validated = $request->validate([
            'sound' => ['nullable', Rule::enum(NotificationSound::class)],
        ]);

        if (! $notifier->isAvailable()) {
            return back()->with('error', 'Desktop notifications only work in the desktop app.');
        }

        $notifier->send(config('app.name'), 'Notifications are working.', sound: NotificationSound::tryFrom($validated['sound'] ?? ''));

        return back()->with('success', 'Test notification sent.');
    }
}
