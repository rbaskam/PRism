<?php

use App\Services\AppSettings;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Event;

/**
 * Whether the queue worker with this --name would take its next job, as Laravel's worker asks before each one.
 */
function takesNextJob(string $workerName): bool
{
    event(new WorkerStarting('database', 'reviews', new WorkerOptions(name: $workerName)));

    return Event::until(new Looping('database', 'reviews')) !== false;
}

it('pauses the review runners above the number the user chose, between jobs', function () {
    app(AppSettings::class)->set(AppSettings::REVIEW_RUNNERS, 2);

    expect(takesNextJob('reviews'))->toBeTrue()
        ->and(takesNextJob('reviews-2'))->toBeTrue()
        ->and(takesNextJob('reviews-3'))->toBeFalse()
        ->and(takesNextJob('default'))->toBeTrue();
});

it('lets a paused review runner take jobs again when the user chooses more', function () {
    app(AppSettings::class)->set(AppSettings::REVIEW_RUNNERS, 1);
    expect(takesNextJob('reviews-2'))->toBeFalse();

    app(AppSettings::class)->set(AppSettings::REVIEW_RUNNERS, 3);

    expect(takesNextJob('reviews-2'))->toBeTrue();
});
