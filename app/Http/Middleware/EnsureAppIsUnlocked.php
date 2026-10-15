<?php

namespace App\Http\Middleware;

use App\Services\AppLock;
use Closure;
use Illuminate\Http\Request;
use Inertia\Support\Header;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shows the lock screen when the app lock is on and this session hasn't been unlocked,
 * or has been idle for longer than the auto-lock time.
 */
class EnsureAppIsUnlocked
{
    public function __construct(private readonly AppLock $appLock) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();

        if ($this->appLock->isLocked($session)) {
            $this->appLock->lock($session);

            if ($request->expectsJson() && ! $request->header(Header::INERTIA)) {
                return response()->json(['message' => 'The app is locked.'], Response::HTTP_LOCKED);
            }

            if ($request->isMethod('GET') && ! $this->isBackgroundRequest($request)) {
                $session->put('url.intended', $request->fullUrl());
            }

            return redirect()->route('lock.show');
        }

        if (! $this->isBackgroundRequest($request)) {
            $this->appLock->recordActivity($session);
        }

        return $next($request);
    }

    /**
     * Polls and deferred props reload part of a page without the user doing anything, so they don't count as activity.
     */
    private function isBackgroundRequest(Request $request): bool
    {
        return $request->hasHeader(Header::PARTIAL_ONLY);
    }
}
