<?php

use Illuminate\Http\Request;

/*
 * Both checks stay out of the way of the test suite (browser tests can't send the desktop app's secret), so these tests
 * switch the environment to the one the app runs in.
 */
beforeEach(function () {
    $this->app['env'] = 'production';
});

afterEach(function () {
    Request::setTrustedHosts([]);
});

it('answers only the desktop app window, which sends the secret', function (array $headers, int $status) {
    config(['nativephp-internal.running' => true, 'nativephp-internal.secret' => 'test-native-secret']);

    $response = $this->withHeaders($headers)->get('http://127.0.0.1:8100/up');

    $response->assertStatus($status);
})->with([
    'a browser without the secret' => [[], 403],
    'a browser with the wrong secret' => [['X-NativePHP-Secret' => 'guessed'], 403],
    'the desktop app window' => [['X-NativePHP-Secret' => 'test-native-secret'], 200],
]);

it('accepts the desktop app secret from its cookie', function () {
    config(['nativephp-internal.running' => true, 'nativephp-internal.secret' => 'test-native-secret']);

    $this->withUnencryptedCookie('_php_native', 'test-native-secret')->get('http://localhost:8100/up')->assertOk();
});

it('does not ask for the secret outside the desktop app', function () {
    $this->get('http://127.0.0.1:8100/up')->assertOk();
});

it('refuses requests addressed to another hostname', function (string $environment, string $url) {
    $this->app['env'] = $environment;

    $this->get($url)->assertBadRequest();
})->with([
    'a website pointing its name at this computer' => ['production', 'http://attacker.example.com:8100/up'],
    'a name that only starts like this computer' => ['production', 'http://127.0.0.1.attacker.example.com:8100/up'],
    'while developing' => ['local', 'http://attacker.example.com:8100/up'],
]);

it('answers requests addressed to this computer', function (string $url) {
    $this->get($url)->assertOk();
})->with([
    'the desktop app' => 'http://127.0.0.1:8100/up',
    'the web version' => 'http://localhost:7081/up',
    'IPv6' => 'http://[::1]:7081/up',
]);
