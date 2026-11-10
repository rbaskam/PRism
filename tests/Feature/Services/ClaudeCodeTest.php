<?php

use App\Enums\ClaudeAuthMode;
use App\Services\AppSettings;
use App\Services\Claude\ClaudeCode;
use App\Services\Git\Git;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    app(AppSettings::class)->set(AppSettings::CLAUDE_AUTH_MODE, ClaudeAuthMode::CliLogin->value);
    app(AppSettings::class)->set(AppSettings::CLAUDE_BINARY_PATH, PHP_BINARY);
    Process::fake(['*' => Process::result(json_encode([
        'type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => '', 'structured_output' => ['answer' => 'ok'],
    ]))]);
});

/**
 * The values given to an option that takes a list, such as --allowedTools.
 *
 * @param  list<string>  $command
 * @return list<string>
 */
function optionValues(array $command, string $option): array
{
    $values = [];

    for ($index = array_search($option, $command, true) + 1; $index < count($command) && ! str_starts_with($command[$index], '--'); $index++) {
        $values[] = $command[$index];
    }

    return $values;
}

function runClaude(?string $workingDirectory = null): void
{
    app(ClaudeCode::class)->run('Review it', ['type' => 'object'], workingDirectory: $workingDirectory, githubToken: 'ghp_test', repository: 'acme/app');
}

it("never loads the pull request's own Claude Code settings, and turns hooks off", function () {
    runClaude(sys_get_temp_dir());

    Process::assertRan(function (PendingProcess $process): bool {
        $command = $process->command;
        $settings = json_decode($command[array_search('--settings', $command) + 1], true);

        return $command[array_search('--setting-sources', $command) + 1] === 'user'
            && $settings['disableAllHooks'] === true
            && $settings['permissions']['blockReadsOutsideWorkingDirectories'] === true
            && in_array('--strict-mcp-config', $command, true)
            && $command[array_search('--permission-mode', $command) + 1] === 'dontAsk';
    });
});

it('only lets Claude read files in the working directory and never fetch', function () {
    runClaude();

    Process::assertRan(function (PendingProcess $process): bool {
        $allowed = optionValues($process->command, '--allowedTools');

        return in_array('Read(./**)', $allowed, true)
            && array_intersect(['Read', 'Grep', 'Glob', 'Edit', 'Write'], $allowed) === []
            && collect($allowed)->every(fn (string $rule): bool => ! str_contains($rule, 'fetch') && ! str_starts_with($rule, 'Edit'));
    });
});

it('denies commands that point gh at another repository or make git run programs and write files', function () {
    runClaude();

    Process::assertRan(function (PendingProcess $process): bool {
        $denied = optionValues($process->command, '--disallowedTools');

        return array_diff([
            'Bash(gh * --repo*)',
            'Bash(gh * -R*)',
            'Bash(gh * --hostname*)',
            'Bash(git -c*)',
            'Bash(git * --output*)',
            'Bash(git * --upload-pack*)',
            'Bash(git fetch *)',
        ], $denied) === [];
    });
});

it('points gh at the pull request repository and runs git without hooks', function () {
    runClaude();

    Process::assertRan(fn (PendingProcess $process): bool => $process->environment['GH_REPO'] === 'acme/app'
        && $process->environment['GH_TOKEN'] === 'ghp_test'
        && $process->environment['GIT_CONFIG_KEY_0'] === 'core.hooksPath'
        && $process->environment['GIT_CONFIG_VALUE_0'] === Git::emptyHooksDirectory()
        && is_dir($process->environment['GIT_CONFIG_VALUE_0'])
        && glob($process->environment['GIT_CONFIG_VALUE_0'].'/*') === []);
});

it("keeps PRism's own secrets out of Claude's environment", function () {
    putenv('NATIVEPHP_SECRET=native-secret');
    $_ENV['NATIVEPHP_API_URL'] = 'http://127.0.0.1:4000/api/';
    $_ENV['PRISM_GIT_TOKEN'] = 'ghp_test';

    try {
        runClaude();
    } finally {
        putenv('NATIVEPHP_SECRET');
        unset($_ENV['NATIVEPHP_API_URL'], $_ENV['PRISM_GIT_TOKEN']);
    }

    Process::assertRan(fn (PendingProcess $process): bool => $process->environment['NATIVEPHP_SECRET'] === false
        && $process->environment['NATIVEPHP_API_URL'] === false
        && $process->environment['PRISM_GIT_TOKEN'] === false
        && $process->environment['APP_KEY'] === false);
});

it('runs Claude in its own empty workspace when there is no checkout', function () {
    runClaude();

    Process::assertRan(fn (PendingProcess $process): bool => $process->path === storage_path('app/claude-workspace'));
});

it('gives Claude the time left before a deadline, within limits', function (int $secondsLeft, int $expected) {
    $this->freezeTime();

    expect(ClaudeCode::timeoutBefore(now()->addSeconds($secondsLeft), 1500))->toBe($expected);
})->with([
    'plenty of time' => [3000, 1500],
    'less than the maximum' => [900, 900],
    'almost none' => [10, ClaudeCode::MINIMUM_TIMEOUT_SECONDS],
]);
