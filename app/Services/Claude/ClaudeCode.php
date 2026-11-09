<?php

namespace App\Services\Claude;

use App\Enums\ClaudeAuthMode;
use App\Services\AppSettings;
use App\Services\CommandLocator;
use App\Services\Github\GithubCli;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

/**
 * Runs the Claude Code CLI headlessly (`claude -p`) with read-only tools.
 */
class ClaudeCode
{
    /**
     * Built-in tools Claude may use. Nothing that edits files or reaches the web.
     *
     * @var list<string>
     */
    public const AVAILABLE_TOOLS = ['Bash', 'Read', 'Grep', 'Glob'];

    /**
     * Built-in tools Claude may use when it is allowed to change code in a worktree.
     *
     * @var list<string>
     */
    public const EDITING_TOOLS = ['Bash', 'Read', 'Grep', 'Glob', 'Edit', 'Write'];

    /**
     * What Claude may do without asking when changing code: edit files and make local commits. Never push.
     *
     * @var list<string>
     */
    public const EDITING_COMMANDS = [
        'Read',
        'Grep',
        'Glob',
        'Edit',
        'Write',
        'Bash(git status *)',
        'Bash(git diff *)',
        'Bash(git log *)',
        'Bash(git show *)',
        'Bash(git add *)',
        'Bash(git rm *)',
        'Bash(git mv *)',
        'Bash(git commit *)',
        'Bash(gh pr view *)',
        'Bash(gh pr diff *)',
    ];

    /**
     * Commands Claude may run without asking. Every one of them only reads.
     *
     * @var list<string>
     */
    public const READ_ONLY_COMMANDS = [
        'Bash(gh pr view *)',
        'Bash(gh pr diff *)',
        'Bash(gh pr checks *)',
        'Bash(git log *)',
        'Bash(git show *)',
        'Bash(git diff *)',
        'Bash(git blame *)',
        'Bash(git fetch *)',
        'Read',
        'Grep',
        'Glob',
    ];

    /**
     * How often to read Claude's output while it works, when the caller can stop it. Each read only takes what fits in
     * the pipe, so reading often keeps a long answer from trickling in.
     */
    public const POLL_MILLISECONDS = 100;

    /**
     * Ask whether Claude should keep going every this many reads: about once a second.
     */
    public const POLLS_PER_CHECK = 10;

    /**
     * SIGTERM, which the `pcntl` constant isn't always loaded for.
     */
    private const SIGTERM = 15;

    public function __construct(
        private readonly AppSettings $settings,
        private readonly CommandLocator $locator,
        private readonly GithubCli $githubCli,
    ) {}

    public function binary(): ?string
    {
        return $this->locator->find('claude', $this->settings->claudeBinaryPath());
    }

    /**
     * Describe the local Claude Code install and, for CLI logins, whether it is signed in.
     *
     * @return array{installed: bool, binary: ?string, version: ?string, logged_in: ?bool, auth_method: ?string, error: ?string}
     */
    public function status(): array
    {
        $binary = $this->binary();
        $status = ['installed' => $binary !== null, 'binary' => $binary, 'version' => null, 'logged_in' => null, 'auth_method' => null, 'error' => null];

        if ($binary === null) {
            return [...$status, 'error' => 'Claude Code was not found. Install it from https://claude.com/claude-code or set its path.'];
        }

        $version = Process::env($this->environment())->timeout(20)->run([$binary, '--version']);

        if ($version->failed()) {
            return [...$status, 'error' => trim($version->errorOutput()) ?: 'Claude Code could not be started.'];
        }

        $status['version'] = trim($version->output());

        $auth = Process::env($this->environment())->timeout(20)->run([$binary, 'auth', 'status', '--json']);
        $decoded = json_decode($auth->output(), true);

        if (is_array($decoded)) {
            $status['logged_in'] = (bool) ($decoded['loggedIn'] ?? false);
            $status['auth_method'] = $decoded['authMethod'] ?? null;
        }

        return $status;
    }

    /**
     * Run a prompt and return Claude's structured answer.
     *
     * Pass `$keepRunning` to be able to stop Claude part way: it's asked about once a second while Claude works, and
     * Claude is stopped as soon as it returns false.
     *
     * @param  array<string, mixed>  $jsonSchema
     * @param  list<string>  $allowedTools
     * @param  list<string>  $tools
     * @param  (Closure(): bool)|null  $keepRunning
     *
     * @throws ClaudeException
     * @throws ClaudeStoppedException when `$keepRunning` returned false
     */
    public function run(
        string $prompt,
        array $jsonSchema,
        ?string $systemPrompt = null,
        ?string $workingDirectory = null,
        ?string $githubToken = null,
        array $allowedTools = self::READ_ONLY_COMMANDS,
        int $timeoutSeconds = 1500,
        array $tools = self::AVAILABLE_TOOLS,
        ?Closure $keepRunning = null,
    ): ClaudeResult {
        $binary = $this->binary() ?? throw new ClaudeException('Claude Code was not found. Set its path in Settings → Claude.');

        if (! $this->settings->isClaudeConfigured()) {
            throw new ClaudeException('Connect Claude in Settings → Claude before running a review.');
        }

        $process = Process::path($workingDirectory ?? $this->defaultWorkingDirectory())
            ->env($this->environment($githubToken))
            ->input($prompt)
            ->timeout($timeoutSeconds);
        $arguments = $this->arguments($binary, $jsonSchema, $systemPrompt, $allowedTools, $tools);

        $result = $keepRunning === null
            ? $process->run($arguments)
            : $this->runWhile($process, $arguments, $keepRunning, $timeoutSeconds);

        if ($result->failed() && trim($result->output()) === '') {
            throw new ClaudeException(trim($result->errorOutput()) ?: "Claude Code exited with code {$result->exitCode()}.");
        }

        return ClaudeResult::fromJson($result->output());
    }

    /**
     * @param  array<string, mixed>  $jsonSchema
     * @param  list<string>  $allowedTools
     * @param  list<string>  $tools
     * @return list<string>
     */
    public function arguments(string $binary, array $jsonSchema, ?string $systemPrompt, array $allowedTools, array $tools = self::AVAILABLE_TOOLS): array
    {
        $arguments = [
            $binary,
            '-p',
            '--output-format', 'json',
            '--json-schema', json_encode($jsonSchema),
            '--tools', implode(',', $tools),
            '--allowedTools', ...$allowedTools,
            '--permission-mode', 'dontAsk',
            '--strict-mcp-config',
            '--no-session-persistence',
        ];

        if ($model = $this->settings->claudeModel()) {
            array_push($arguments, '--model', $model);
        }

        if ($budget = $this->settings->claudeMaxBudgetUsd()) {
            array_push($arguments, '--max-budget-usd', (string) $budget);
        }

        if (filled($systemPrompt)) {
            array_push($arguments, '--append-system-prompt', $systemPrompt);
        }

        return $arguments;
    }

    /**
     * The environment for Claude: a PATH that finds `gh`, the GitHub token for `gh`, and Claude credentials.
     *
     * @return array<string, string|false>
     */
    public function environment(?string $githubToken = null): array
    {
        $environment = [
            'PATH' => $this->locator->searchPath([$this->binary(), $this->githubCli->binary()]),
            'NO_COLOR' => '1',
        ];

        if ($githubToken !== null) {
            $environment['GH_TOKEN'] = $githubToken;
            $environment['GH_PROMPT_DISABLED'] = '1';
        }

        $credential = $this->settings->claudeCredential();

        return match ($this->settings->claudeAuthMode()) {
            ClaudeAuthMode::ApiKey => [...$environment, 'ANTHROPIC_API_KEY' => (string) $credential, 'CLAUDE_CODE_OAUTH_TOKEN' => false],
            ClaudeAuthMode::OauthToken => [...$environment, 'CLAUDE_CODE_OAUTH_TOKEN' => (string) $credential, 'ANTHROPIC_API_KEY' => false],
            default => $environment,
        };
    }

    /**
     * Start Claude and wait for it, stopping it as soon as `$keepRunning` returns false or it runs out of time.
     *
     * @param  list<string>  $arguments
     * @param  Closure(): bool  $keepRunning
     *
     * @throws ClaudeException
     * @throws ClaudeStoppedException
     */
    private function runWhile(PendingProcess $pending, array $arguments, Closure $keepRunning, int $timeoutSeconds): ProcessResult
    {
        $process = $pending->start($arguments);
        $deadline = now()->addSeconds($timeoutSeconds);

        for ($polls = 0; $process->running(); $polls++) {
            $stopped = $polls % self::POLLS_PER_CHECK === 0 && ! $keepRunning();

            if ($stopped || now()->greaterThan($deadline)) {
                // Symfony kills the process outright if it's still running once nothing refers to it any more.
                rescue(fn () => $process->signal(self::SIGTERM), report: false);

                throw $stopped
                    ? new ClaudeStoppedException('Claude was stopped before it finished.')
                    : new ClaudeException('Claude Code did not finish within '.intdiv($timeoutSeconds, 60).' minutes.');
            }

            Sleep::for(self::POLL_MILLISECONDS)->milliseconds();
        }

        return $process->wait();
    }

    private function defaultWorkingDirectory(): string
    {
        $directory = storage_path('app/claude-workspace');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $directory;
    }
}
