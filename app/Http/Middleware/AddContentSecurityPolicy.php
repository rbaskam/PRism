<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tells the browser (or the desktop app's window) to run only PRism's own scripts, so markup that slips into a page,
 * e.g. from a pull request description, can't run code, load a form or frame the app.
 *
 * Inline scripts need this request's nonce: Vite and Ziggy add it to their tags, and app.blade.php to its own.
 */
class AddContentSecurityPolicy
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        if ($this->isPage($response)) {
            $response->headers->set('Content-Security-Policy', $this->policy($nonce));
        }

        return $response;
    }

    /**
     * HTML responses only, and not Laravel's debug error page while developing, which needs its own inline scripts.
     */
    private function isPage(Response $response): bool
    {
        return str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')
            && ! (config('app.debug') && $response->getStatusCode() >= Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    private function policy(string $nonce): string
    {
        ['assets' => $devServer, 'socket' => $devServerSocket] = $this->viteDevServer();

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'", ...$devServer],
            // Vue and the components it uses set inline styles, and syntax highlighting colours code with them.
            'style-src' => ["'self'", "'unsafe-inline'", 'https://fonts.bunny.net', ...$devServer],
            // The font stylesheet from fonts.bunny.net loads its font files from other hosts. Fonts can't run code.
            'font-src' => ["'self'", 'data:', 'https:', ...$devServer],
            // Avatars, and images and videos in pull request descriptions and comments, come from GitHub's hosts.
            'img-src' => ["'self'", 'data:', 'https:', ...$devServer],
            'media-src' => ["'self'", 'https:'],
            'connect-src' => ["'self'", ...$devServer, ...$devServerSocket],
            'object-src' => ["'none'"],
            'base-uri' => ["'none'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
        ];

        return collect($directives)
            ->map(fn (array $sources, string $directive): string => $directive.' '.implode(' ', $sources))
            ->implode('; ');
    }

    /**
     * While `npm run dev` is running, pages load their scripts and styles from the Vite dev server, which pushes
     * updates over a websocket. A policy can't name an IPv6 address such as [::1], so that allows the scheme instead.
     *
     * @return array{assets: list<string>, socket: list<string>}
     */
    private function viteDevServer(): array
    {
        $url = Vite::isRunningHot() ? trim((string) file_get_contents(Vite::hotFile())) : '';
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($scheme) || ! is_string($host)) {
            return ['assets' => [], 'socket' => []];
        }

        $socketScheme = $scheme === 'https' ? 'wss' : 'ws';

        if (str_starts_with($host, '[')) {
            return ['assets' => ["{$scheme}:"], 'socket' => ["{$socketScheme}:"]];
        }

        return ['assets' => [$url], 'socket' => [$socketScheme.substr($url, strlen($scheme))]];
    }
}
