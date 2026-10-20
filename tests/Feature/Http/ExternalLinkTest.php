<?php

use Native\Desktop\Facades\Shell;

it('opens a link in the system browser and answers without reloading the page', function () {
    config(['nativephp-internal.running' => true]);
    Shell::fake();

    $response = $this->postJson(route('external-links.store'), ['url' => 'https://github.com/acme/api/pull/1/files']);

    $response->assertOk()->assertExactJson(['opened' => true]);
    Shell::assertOpenedExternal('https://github.com/acme/api/pull/1/files');
});

it('only opens https links', function () {
    config(['nativephp-internal.running' => true]);
    Shell::fake();

    $response = $this->postJson(route('external-links.store'), ['url' => 'file:///etc/passwd']);

    $response->assertUnprocessable()->assertJsonValidationErrors('url');
});
