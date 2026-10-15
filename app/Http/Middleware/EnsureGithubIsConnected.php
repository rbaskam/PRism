<?php

namespace App\Http\Middleware;

use App\Models\GithubAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends the user to the first onboarding step until a GitHub account is connected.
 */
class EnsureGithubIsConnected
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (GithubAccount::current() === null) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Connect GitHub first.'], Response::HTTP_CONFLICT)
                : redirect()->route('onboarding.github');
        }

        return $next($request);
    }
}
