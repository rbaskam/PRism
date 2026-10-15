<?php

use App\Services\Git\Git;

it('reads the GitHub repository from a remote URL', function (string $url, ?string $expected) {
    expect(Git::parseGithubRemote($url))->toBe($expected);
})->with([
    'ssh' => ['git@github.com:acme/billing-api.git', 'acme/billing-api'],
    'https' => ['https://github.com/acme/billing-api.git', 'acme/billing-api'],
    'https without .git' => ['https://github.com/acme/billing-api', 'acme/billing-api'],
    'ssh url form' => ['ssh://git@github.com/acme/billing-api.git', 'acme/billing-api'],
    'not github' => ['git@gitlab.com:acme/billing-api.git', null],
]);
