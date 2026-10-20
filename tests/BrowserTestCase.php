<?php

namespace Tests;

use App\Services\CommandLocator;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Mockery\MockInterface;
use Tests\Browser\Support\FakeGithub;

/**
 * Browser tests run the real Vue app, so unlike feature tests they load the compiled assets: run `npm run build` first.
 * GitHub is replaced by FakeGithub, and the claude, gh and git commands are never found or run, so a page behaves the
 * same on every machine.
 */
abstract class BrowserTestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Process::fake();
        $this->partialMock(CommandLocator::class, fn (MockInterface $mock) => $mock->shouldReceive('find')->andReturnNull());
    }

    protected function tearDown(): void
    {
        $unhandled = $this->app?->bound(FakeGithub::class) ? $this->app->make(FakeGithub::class)->unhandledRequests : [];

        parent::tearDown();

        $this->assertSame([], $unhandled, 'The page called GitHub in a way FakeGithub does not handle.');
    }
}
