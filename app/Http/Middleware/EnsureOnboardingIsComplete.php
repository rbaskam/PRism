<?php

namespace App\Http\Middleware;

use App\Services\AppSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends the user back to onboarding until they have chosen repositories and passed the Claude step.
 */
class EnsureOnboardingIsComplete
{
    public function __construct(private readonly AppSettings $settings) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->settings->hasCompletedOnboarding()) {
            return redirect()->route('onboarding.repositories');
        }

        return $next($request);
    }
}
