<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Native\Desktop\Http\Middleware\PreventRegularBrowserAccess as Middleware;

/**
 * In the desktop app, only its own window may use the server: Electron adds a secret header (or cookie) to every
 * request it makes, and anything else, such as a web page in the user's browser, gets 403. Outside the desktop app
 * it does nothing.
 *
 * NativePHP registers its version itself, but on a kernel instance that never handles requests, so this one is
 * registered in bootstrap/app.php. Like TrustHosts, it stays out of the way of the test suite, whose browser tests
 * can't send the secret; tests that need it switch the environment.
 */
class PreventRegularBrowserAccess extends Middleware
{
    public function __construct(private readonly Application $app) {}

    /**
     * @param  Closure(Request): mixed  $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if ($this->app->runningUnitTests()) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
