<?php

use App\Enums\AlertType;
use App\Enums\NotificationSound;
use App\Services\AppSettings;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    onboardedAccount();
});

it('shows the saved notification settings, with defaults for alerts the user has not changed', function () {
    app(AppSettings::class)->setMany([
        AppSettings::NOTIFY_DESKTOP => false,
        AppSettings::NOTIFY_SOUND => NotificationSound::None->value,
        'notifications.'.AlertType::Mentions->value => false,
        'notifications.'.AlertType::Ci->value => true,
    ]);

    $response = $this->get(route('settings.notifications'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('settings/Notifications')
        ->where('settings.desktop', false)
        ->where('settings.mentions', false)
        ->where('settings.ci', true)
        ->where('settings.review_requests', true)
        ->where('settings.new_pull_requests', false)
        ->where('settings.follow_up_activity', false)
        ->where('settings.sound', 'none')
        ->where('sounds', fn ($sounds): bool => collect($sounds)->contains(['value' => 'none', 'label' => 'None']))
        ->where('desktopAvailable', false));
});

it('needs every alert switch to save notification settings', function () {
    $response = $this->put(route('settings.notifications.update'), [
        'desktop' => true,
        'follow_up_activity' => false,
        'sound' => NotificationSound::Default->value,
    ]);

    $response->assertSessionHasErrors(collect(AlertType::cases())->map(fn (AlertType $type): string => $type->value)->all());
    expect(app(AppSettings::class)->get(AppSettings::NOTIFY_SOUND))->toBeNull();
});

it('explains that test notifications only work in the desktop app', function () {
    config(['nativephp-internal.running' => false]);

    $response = $this->from(route('settings.notifications'))->post(route('settings.notifications.test'));

    $response->assertRedirect(route('settings.notifications'))
        ->assertSessionHas('error', 'Desktop notifications only work in the desktop app.');
    Http::assertNothingSent();
});

it('rejects a test notification sound that does not exist', function () {
    config(['nativephp-internal.running' => true]);

    $response = $this->post(route('settings.notifications.test'), ['sound' => 'airhorn']);

    $response->assertSessionHasErrors('sound');
    Http::assertNothingSent();
});
