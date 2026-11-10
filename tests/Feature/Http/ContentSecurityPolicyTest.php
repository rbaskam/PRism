<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;

beforeEach(function () {
    // An empty public folder, so `npm run dev` running on this machine doesn't change the policy.
    $this->app->usePublicPath(sys_get_temp_dir().'/prism-public-'.uniqid());
});

it('lets a page run only its own scripts and the inline ones it marks with the request nonce', function () {
    onboardedAccount();

    $response = $this->get(route('dashboard'));

    $policy = (string) $response->headers->get('Content-Security-Policy');
    $nonce = Str::betweenFirst($policy, "'nonce-", "'");
    preg_match_all('/<script\b[^>]*>/', (string) $response->getContent(), $scripts);
    expect($policy)
        ->toContain("script-src 'self' 'nonce-{$nonce}';")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'none'")
        ->toContain("form-action 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("img-src 'self' data: https:;")
        ->toContain("connect-src 'self';")
        ->and(strlen($nonce))->toBeGreaterThanOrEqual(32)
        ->and($scripts[0])->not->toBeEmpty()
        ->each->toContain("nonce=\"{$nonce}\"");
});

it('uses a new nonce for every page', function () {
    onboardedAccount();

    $first = $this->get(route('dashboard'))->headers->get('Content-Security-Policy');
    $second = $this->get(route('dashboard'))->headers->get('Content-Security-Policy');

    expect($first)->not->toBe($second);
});

it('allows the Vite dev server only while it is running', function (string $devServer, array $allowed) {
    onboardedAccount();
    $hotFile = sys_get_temp_dir().'/prism-hot-'.uniqid();
    File::put($hotFile, $devServer);
    $this->withVite();
    Vite::useHotFile($hotFile);

    $policy = (string) $this->get(route('dashboard'))->headers->get('Content-Security-Policy');

    File::delete($hotFile);
    expect($policy)->toContain(...$allowed);
})->with([
    'on a hostname' => ['http://localhost:5173', ["'nonce-", 'http://localhost:5173', 'connect-src \'self\' http://localhost:5173 ws://localhost:5173']],
    'on an IPv6 address, which a policy cannot name' => ['http://[::1]:5173', ["script-src 'self' 'nonce-", "' http:;", "connect-src 'self' http: ws:"]],
]);

it('does not allow any dev server when Vite is not running', function () {
    onboardedAccount();

    $policy = (string) $this->get(route('dashboard'))->headers->get('Content-Security-Policy');

    expect($policy)->not->toContain('5173')
        ->not->toContain('ws:');
});
