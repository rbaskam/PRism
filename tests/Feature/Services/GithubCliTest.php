<?php

use App\Services\CommandLocator;
use App\Services\Github\GithubCli;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->mock(CommandLocator::class, function ($locator): void {
        $locator->shouldReceive('find')->andReturn('/opt/fake/bin/gh');
        $locator->shouldReceive('searchPath')->andReturn('/opt/fake/bin');
    });
});

it('reads the token the GitHub CLI is logged in with, without passing on PRism\'s secrets', function () {
    Process::fake(['*' => Process::result("ghp_test\n")]);
    putenv('NATIVEPHP_SECRET=native-secret');

    try {
        $token = app(GithubCli::class)->token();
    } finally {
        putenv('NATIVEPHP_SECRET');
    }

    expect($token)->toBe('ghp_test');
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['/opt/fake/bin/gh', 'auth', 'token']
        && $process->environment['PATH'] === '/opt/fake/bin'
        && $process->environment['NATIVEPHP_SECRET'] === false);
});

it('has no token when the GitHub CLI isn\'t logged in', function () {
    Process::fake(['*' => Process::result('', 'You are not logged into any GitHub hosts.', 1)]);

    expect(app(GithubCli::class)->token())->toBeNull();
});
