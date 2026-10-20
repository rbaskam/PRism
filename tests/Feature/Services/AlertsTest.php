<?php

use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\PullRequest;
use App\Services\Alerts;
use App\Services\AppSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['nativephp-internal.running' => true]);
    Http::fake(['*/api/notification' => Http::response(['reference' => 'x'])]);
});

it('keeps the alert for the bell and shows it on the desktop', function () {
    $pullRequest = PullRequest::factory()->create();

    $alert = app(Alerts::class)->raise(AlertType::ReviewFinished, 'AI review finished', 'acme/app#7: 2 new findings', Alerts::pullRequestUrl($pullRequest, 'findings'), $pullRequest);

    expect($alert)
        ->title->toBe('AI review finished')
        ->url->toBe("/pull-requests/{$pullRequest->id}?tab=findings")
        ->read_at->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request['title'] === 'AI review finished'
        && $request['reference'] === "pull-request:{$pullRequest->id}");
});

it('keeps the alert but shows nothing on the desktop when desktop notifications are off', function () {
    app(AppSettings::class)->set(AppSettings::NOTIFY_DESKTOP, false);

    app(Alerts::class)->raise(AlertType::ReviewFinished, 'AI review finished', 'acme/app#7: no new findings');

    expect(Alert::sole()->title)->toBe('AI review finished');
    Http::assertNothingSent();
});

it('raises nothing for a kind of alert the user switched off', function () {
    app(AppSettings::class)->set('notifications.'.AlertType::Mentions->value, false);

    $alert = app(Alerts::class)->raise(AlertType::Mentions, 'You were mentioned', 'acme/app: Add rate limiting');

    expect($alert)->toBeNull()
        ->and(Alert::query()->count())->toBe(0);
    Http::assertNothingSent();
});
