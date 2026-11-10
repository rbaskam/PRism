<?php

use App\Http\Middleware\AddContentSecurityPolicy;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventRegularBrowserAccess;
use App\Http\Middleware\TrustHosts;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Middleware\TrustHosts as LaravelTrustHosts;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // In the desktop app, only its own window may use the server.
        $middleware->prepend(PreventRegularBrowserAccess::class);

        // Refuse requests for any other hostname, e.g. a website that points its own name at 127.0.0.1.
        $middleware->trustHosts(at: TrustHosts::LOOPBACK_HOSTS);
        $middleware->replace(LaravelTrustHosts::class, TrustHosts::class);

        $middleware->web(append: [
            AddContentSecurityPolicy::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // A failed validation flashes the submitted form to the session, which is stored unencrypted. Keep secrets out of it.
        $exceptions->dontFlash(['token', 'credential', 'pin', 'pin_confirmation', 'current_pin']);

        // When a page has been open longer than its session, e.g. the lock screen, send the user back to it with
        // a fresh token instead of leaving them on Laravel's "419 Page expired" error.
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if ($response->getStatusCode() === 419 && ! $request->expectsJson()) {
                return back()->with('error', 'That page had been open too long, so it was reloaded. Please try again.');
            }

            return $response;
        });
    })->create();
