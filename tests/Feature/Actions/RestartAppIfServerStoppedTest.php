<?php

use App\Actions\RestartAppIfServerStopped;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
    config(['nativephp-internal.running' => true, 'nativephp-internal.api_url' => 'http://native.test/api/']);
});

/**
 * NativePHP's window list, with the main window on PRism's server at 127.0.0.1:8100.
 *
 * @return array<string, mixed>
 */
function nativeApiWithMainWindow(): array
{
    return [
        'native.test/api/window/all' => Http::response([['id' => 'main', 'url' => 'http://127.0.0.1:8100/pull-requests/5?_windowId=main']]),
        'native.test/api/app/relaunch' => Http::response(),
    ];
}

function relaunchWasRequested(): bool
{
    return Http::recorded(fn (Request $request): bool => $request->url() === 'http://native.test/api/app/relaunch')->isNotEmpty();
}

it('relaunches the app when another program answers on its server\'s port', function () {
    Http::fake([...nativeApiWithMainWindow(), '127.0.0.1:8100/up' => Http::response('404 page not found', 404)]);

    expect(app(RestartAppIfServerStopped::class)->handle())->toBeTrue()
        ->and(relaunchWasRequested())->toBeTrue();
    Sleep::assertSleptTimes(1);
});

it('relaunches the app when nothing answers on its server\'s port', function () {
    Http::fake([
        ...nativeApiWithMainWindow(),
        '127.0.0.1:8100/up' => fn () => throw new ConnectionException('cURL error 7: Failed to connect to 127.0.0.1 port 8100: Connection refused'),
    ]);

    expect(app(RestartAppIfServerStopped::class)->handle())->toBeTrue()
        ->and(relaunchWasRequested())->toBeTrue();
});

it('leaves the app alone while its server answers', function () {
    Http::fake([...nativeApiWithMainWindow(), '127.0.0.1:8100/up' => Http::response('<p>Application up</p>')]);

    expect(app(RestartAppIfServerStopped::class)->handle())->toBeFalse()
        ->and(relaunchWasRequested())->toBeFalse();
});

it('leaves the app alone while its server is busy with another request', function () {
    Http::fake([
        ...nativeApiWithMainWindow(),
        '127.0.0.1:8100/up' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds'),
    ]);

    expect(app(RestartAppIfServerStopped::class)->handle())->toBeFalse()
        ->and(relaunchWasRequested())->toBeFalse();
});

it('leaves the app alone when its server only stopped for a moment', function () {
    Http::fake([
        ...nativeApiWithMainWindow(),
        '127.0.0.1:8100/up' => Http::sequence()->push('404 page not found', 404)->push('<p>Application up</p>'),
    ]);

    expect(app(RestartAppIfServerStopped::class)->handle())->toBeFalse()
        ->and(relaunchWasRequested())->toBeFalse();
});

it('isn\'t logged as a failed scheduled task while the server answers', function () {
    Http::fake([...nativeApiWithMainWindow(), '127.0.0.1:8100/up' => Http::response('<p>Application up</p>')]);
    $check = collect(app(Schedule::class)->events())->first(fn (Event $event): bool => $event->description === 'restart-app-if-server-stopped');

    $check->run(app());

    expect($check->exitCode)->toBe(0)
        ->and(relaunchWasRequested())->toBeFalse();
});
