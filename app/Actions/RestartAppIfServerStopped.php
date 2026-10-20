<?php

namespace App\Actions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Native\Desktop\Client\Client;
use Native\Desktop\Facades\App as NativeApp;

/**
 * NativePHP doesn't restart the desktop app's PHP web server when it stops, and another program listening on the
 * same port (e.g. a Docker container on 8100) can then answer the window instead. The scheduler runs in a process of
 * its own every minute, so it checks that each window's server still answers as PRism and relaunches the app when it
 * has stopped.
 */
class RestartAppIfServerStopped
{
    /**
     * Seconds between the two checks that must both find the server stopped before the app is relaunched.
     */
    public const RECHECK_SECONDS = 2;

    public function __construct(private readonly Client $native) {}

    /**
     * @return bool Whether the app was relaunched.
     */
    public function handle(): bool
    {
        $origins = collect((array) $this->native->get('window/all')->json())
            ->map(fn (mixed $window): ?string => is_array($window) ? $this->origin($window['url'] ?? null) : null)
            ->filter()
            ->unique();

        foreach ($origins as $origin) {
            if (! $this->hasStopped($origin)) {
                continue;
            }

            Sleep::for(self::RECHECK_SECONDS)->seconds();

            if ($this->hasStopped($origin)) {
                Log::warning("PRism's web server at {$origin} stopped answering, so the app was relaunched.");
                NativeApp::relaunch();

                return true;
            }
        }

        return false;
    }

    /**
     * Stopped means nothing answers, or something other than PRism does. A server that is busy and slow to answer
     * hasn't stopped: it handles one request at a time.
     *
     * @phpstan-impure
     */
    private function hasStopped(string $origin): bool
    {
        try {
            $response = Http::timeout(10)->get("{$origin}/up");
        } catch (ConnectionException $exception) {
            return str_contains($exception->getMessage(), 'cURL error 7:');
        }

        return ! $this->isPrismHealthPage($response);
    }

    /**
     * Laravel's health page, which says "Application up" (or "Application experiencing problems" with a 500).
     */
    private function isPrismHealthPage(Response $response): bool
    {
        return str_contains($response->body(), 'Application up') || str_contains($response->body(), 'Application experiencing problems');
    }

    private function origin(mixed $url): ?string
    {
        $parts = is_string($url) ? parse_url($url) : false;

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || ! in_array($parts['scheme'], ['http', 'https'], true)) {
            return null;
        }

        return "{$parts['scheme']}://{$parts['host']}".(isset($parts['port']) ? ":{$parts['port']}" : '');
    }
}
