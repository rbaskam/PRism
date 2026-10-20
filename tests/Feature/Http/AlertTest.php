<?php

use App\Enums\AlertType;
use App\Models\Alert;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    onboardedAccount();
});

it('lists the latest alerts, newest first, with how many are unread', function () {
    $older = Alert::factory()->read()->create(['title' => 'Review requested']);
    $newer = Alert::factory()->create(['type' => AlertType::ReviewFinished, 'title' => 'AI review finished', 'url' => '/pull-requests/7?tab=findings']);

    $response = $this->getJson(route('alerts.index'));

    $response->assertOk()
        ->assertJsonPath('unread', 1)
        ->assertJsonPath('alerts.0.id', $newer->id)
        ->assertJsonPath('alerts.0.type', 'review_finished')
        ->assertJsonPath('alerts.0.url', '/pull-requests/7?tab=findings')
        ->assertJsonPath('alerts.0.is_read', false)
        ->assertJsonPath('alerts.1.id', $older->id)
        ->assertJsonPath('alerts.1.is_read', true);
});

it('marks one alert as read', function () {
    $alert = Alert::factory()->create();
    Alert::factory()->create();

    $response = $this->postJson(route('alerts.read', $alert));

    $response->assertOk()->assertExactJson(['unread' => 1]);
    expect($alert->fresh()->read_at)->not->toBeNull();
});

it('marks every alert as read', function () {
    Alert::factory()->count(3)->create();

    $response = $this->postJson(route('alerts.read-all'));

    $response->assertOk()->assertExactJson(['unread' => 0]);
    expect(Alert::query()->unread()->count())->toBe(0);
});

it('tells every page how many alerts are unread', function () {
    Alert::factory()->count(2)->create();
    Alert::factory()->read()->create();

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('counts.unread_alerts', 2));
});

it('deletes alerts after 30 days', function () {
    $old = Alert::factory()->create(['created_at' => now()->subDays(31)]);
    $recent = Alert::factory()->create(['created_at' => now()->subDays(29)]);

    $this->artisan('model:prune', ['--model' => [Alert::class]])->assertSuccessful();

    expect(Alert::query()->pluck('id')->all())->toBe([$recent->id])
        ->and(Alert::find($old->id))->toBeNull();
});
