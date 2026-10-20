<?php

namespace App\Services\Claude;

use App\Enums\ClaudeAuthMode;
use App\Services\AppSettings;
use App\Services\CommandLocator;
use App\Services\Github\GithubCli;
use Illuminate\Support\Facades\Process;

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
     * @param  array<string, mixed>  $jsonSchema
     * @param  list<string>  $allowedTools
     * @param  list<string>  $tools
     *
     * @throws ClaudeException
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
    ): ClaudeResult {
        $binary = $this->binary() ?? throw new ClaudeException('Claude Code was not found. Set its path in Settings → Claude.');

        if (! $this->settings->isClaudeConfigured()) {
            throw new ClaudeException('Connect Claude in Settings → Claude before running a review.');
        }

        $result = Process::path($workingDirectory ?? $this->defaultWorkingDirectory())
            ->env($this->environment($githubToken))
            ->input($prompt)
            ->timeout($timeoutSeconds)
            ->run($this->arguments($binary, $jsonSchema, $systemPrompt, $allowedTools, $tools));

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

    private function defaultWorkingDirectory(): string
    {
        $directory = storage_path('app/claude-workspace');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $directory;
    }
}
