<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts as Middleware;

/**
 * Only answers requests addressed to this computer (and the app URL), so a website that points its own hostname at
 * 127.0.0.1 ("DNS rebinding") can't use the visitor's browser to read PRism's pages or drive its routes.
 *
 * Laravel skips the check in the local environment, but the desktop app runs as "local" under `native:run` and so
 * does the Sail version, so the check applies everywhere except the test suite.
 */
class TrustHosts extends Middleware
{
    /**
     * Symfony matches each pattern as an unanchored, case-insensitive regular expression, so they must be anchored:
     * a bare "127.0.0.1" would also match "127.0.0.1.attacker.example".
     */
    public const LOOPBACK_HOSTS = ['^127\.0\.0\.1$', '^localhost$', '^\[::1\]$'];

    protected function shouldSpecifyTrustedHosts(): bool
    {
        return ! $this->app->runningUnitTests();
    }
}
