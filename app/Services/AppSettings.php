<?php

namespace App\Services;

use App\Enums\AlertType;
use App\Enums\ClaudeAuthMode;
use App\Enums\NotificationSound;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * Typed access to the app's key/value settings. Secrets are encrypted at rest.
 */
class AppSettings
{
    public const REVIEW_PROMPT = 'review.prompt';

    public const ALLOW_POSTING_FINDINGS = 'review.allow_posting_findings';

    public const ALLOW_REPLYING_TO_COMMENTS = 'review.allow_replying_to_comments';

    public const AUTO_REVIEW_ON_REQUEST = 'review.auto_review_on_request';

    public const CLAUDE_AUTH_MODE = 'claude.auth_mode';

    public const CLAUDE_CREDENTIAL = 'claude.credential';

    public const CLAUDE_MODEL = 'claude.model';

    public const CLAUDE_BINARY_PATH = 'claude.binary_path';

    public const CLAUDE_MAX_BUDGET_USD = 'claude.max_budget_usd';

    public const GITHUB_CLI_PATH = 'github.cli_path';

    public const LOCAL_CODE_FOLDER = 'local.code_folder';

    public const ALLOW_CODE_CHANGES = 'review.allow_code_changes';

    public const ALLOW_PUSHING = 'review.allow_pushing';

    public const NOTIFY_DESKTOP = 'notifications.desktop';

    public const NOTIFY_FOLLOW_UP_ACTIVITY = 'notifications.follow_up_activity';

    public const NOTIFY_SOUND = 'notifications.sound';

    public const ONBOARDING_COMPLETED_AT = 'onboarding.completed_at';

    public const APP_LOCK_ENABLED = 'security.lock_enabled';

    public const APP_LOCK_PIN_HASH = 'security.pin_hash';

    public const APP_LOCK_USE_BIOMETRICS = 'security.use_biometrics';

    public const APP_LOCK_AUTO_LOCK_MINUTES = 'security.auto_lock_minutes';

    public const APP_LOCK_ON_LAUNCH = 'security.lock_on_launch';

    public const APP_LOCK_GENERATION = 'security.lock_generation';

    public const DEFAULT_REVIEW_PROMPT = <<<'PROMPT'
        You are a senior engineer reviewing pull request #{number} "{title}" in {repository} ({head} into {base}), opened by @{author}.

        1. Understand the change. Read the description with `gh pr view` and the full diff with `gh pr diff`. If the working directory is a checkout of the pull request, read every changed file in full, plus the code that calls it and the code it calls: bugs often hide in the context the diff doesn't show.
        2. Correctness. Look for logic errors, off-by-one mistakes, wrong conditions, unhandled null, empty or missing values, swallowed exceptions, race conditions, missing transactions, non-idempotent retries, timezone mistakes, and breaking changes to APIs, events or stored data. Check migrations for data loss, locks on large tables and missing indexes.
        3. Security. Check that every new endpoint or action enforces authentication and authorisation, and look for injection (SQL, shell, HTML/XSS), mass assignment, unsafe file paths or URLs (path traversal, SSRF), secrets or personal data in code or logs, missing CSRF protection or rate limits, and insecure defaults.
        4. Performance. Look for N+1 queries, unbounded queries or loops, missing pagination, slow work done during a request that belongs in a queue, and stale caches.
        5. Tests. Flag new behaviour or edge cases without tests, and tests that were deleted, skipped, focused or weakened so they can no longer fail.
        6. Maintainability. Flag duplicated code that should reuse an existing helper, needless complexity, dead code, misleading names, and code that ignores the patterns used elsewhere in the codebase.

        Only report real problems you can point to in the code, with the file and line, and explain why each one matters and how to fix it. Prefer one clear finding over several vague ones, and skip style nitpicks a linter would catch. Severity: critical for data loss, a security hole or an outage; high for a bug users will hit; medium for an edge-case bug or missing tests for important logic; low for maintainability. If the pull request is good, say so and return no findings rather than inventing issues.
        PROMPT;

    /**
     * Placeholders that can be used in the review prompt.
     *
     * @var array<string, string>
     */
    public const REVIEW_PROMPT_PLACEHOLDERS = [
        '{number}' => 'Pull request number',
        '{repository}' => 'owner/name of the repository',
        '{title}' => 'Pull request title',
        '{author}' => 'GitHub login of the author',
        '{head}' => 'Branch being merged',
        '{base}' => 'Branch being merged into',
        '{url}' => 'Link to the pull request',
    ];

    /**
     * @var array<string, mixed>|null
     */
    private ?array $values = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $this->values ??= Setting::query()
            ->pluck('value', 'key')
            ->map(fn (?string $value): mixed => $value === null ? null : json_decode($value, true))
            ->all();

        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value === null ? null : json_encode($value)]);

        $this->values = null;
    }

    /**
     * Store several settings at once.
     *
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }
    }

    public function secret(string $key): ?string
    {
        $encrypted = $this->get($key);

        return is_string($encrypted) ? Crypt::decryptString($encrypted) : null;
    }

    public function setSecret(string $key, ?string $value): void
    {
        $this->set($key, filled($value) ? Crypt::encryptString($value) : null);
    }

    public function reviewPrompt(): string
    {
        return $this->get(self::REVIEW_PROMPT) ?: self::DEFAULT_REVIEW_PROMPT;
    }

    public function allowsPostingFindings(): bool
    {
        return (bool) $this->get(self::ALLOW_POSTING_FINDINGS, false);
    }

    public function allowsReplyingToComments(): bool
    {
        return (bool) $this->get(self::ALLOW_REPLYING_TO_COMMENTS, false);
    }

    public function allowsCodeChanges(): bool
    {
        return (bool) $this->get(self::ALLOW_CODE_CHANGES, false);
    }

    public function allowsPushing(): bool
    {
        return (bool) $this->get(self::ALLOW_PUSHING, false);
    }

    public function reviewsAutomaticallyWhenRequested(): bool
    {
        return (bool) $this->get(self::AUTO_REVIEW_ON_REQUEST, false);
    }

    public function claudeAuthMode(): ?ClaudeAuthMode
    {
        return ClaudeAuthMode::tryFrom((string) $this->get(self::CLAUDE_AUTH_MODE));
    }

    public function isClaudeConfigured(): bool
    {
        return $this->claudeAuthMode() !== null;
    }

    public function claudeCredential(): ?string
    {
        return $this->secret(self::CLAUDE_CREDENTIAL);
    }

    public function claudeModel(): ?string
    {
        return $this->get(self::CLAUDE_MODEL) ?: null;
    }

    public function claudeBinaryPath(): ?string
    {
        return $this->get(self::CLAUDE_BINARY_PATH) ?: null;
    }

    public function claudeMaxBudgetUsd(): ?float
    {
        $budget = $this->get(self::CLAUDE_MAX_BUDGET_USD);

        return is_numeric($budget) && $budget > 0 ? (float) $budget : null;
    }

    /**
     * The Claude connection settings shown on the settings and onboarding pages. Never includes the secret itself.
     *
     * @return array{auth_mode: ?string, has_credential: bool, model: ?string, binary_path: ?string, max_budget_usd: ?float}
     */
    public function claudeConnectionSettings(): array
    {
        return [
            'auth_mode' => $this->claudeAuthMode()?->value,
            'has_credential' => $this->claudeCredential() !== null,
            'model' => $this->claudeModel(),
            'binary_path' => $this->claudeBinaryPath(),
            'max_budget_usd' => $this->claudeMaxBudgetUsd(),
        ];
    }

    public function githubCliPath(): ?string
    {
        return $this->get(self::GITHUB_CLI_PATH) ?: null;
    }

    public function sendsDesktopNotifications(): bool
    {
        return (bool) $this->get(self::NOTIFY_DESKTOP, true);
    }

    /**
     * Whether desktop alerts of this kind are switched on.
     */
    public function sendsAlert(AlertType $type): bool
    {
        return $this->sendsDesktopNotifications() && (bool) $this->get('notifications.'.$type->value, $type->isOnByDefault());
    }

    /**
     * Alert again when a pull request the user was alerted about (a review request or mention) gets more activity.
     */
    public function alertsOnFollowUpActivity(): bool
    {
        return (bool) $this->get(self::NOTIFY_FOLLOW_UP_ACTIVITY, false);
    }

    public function notificationSound(): NotificationSound
    {
        return NotificationSound::tryFrom((string) $this->get(self::NOTIFY_SOUND)) ?? NotificationSound::Default;
    }

    public function notifiesWhenReviewFinishes(): bool
    {
        return $this->sendsAlert(AlertType::ReviewFinished);
    }

    public function isAppLockEnabled(): bool
    {
        return (bool) $this->get(self::APP_LOCK_ENABLED, false) && $this->get(self::APP_LOCK_PIN_HASH) !== null;
    }

    public function unlocksWithBiometrics(): bool
    {
        return (bool) $this->get(self::APP_LOCK_USE_BIOMETRICS, false);
    }

    public function autoLockMinutes(): int
    {
        return (int) $this->get(self::APP_LOCK_AUTO_LOCK_MINUTES, 15);
    }

    public function locksOnLaunch(): bool
    {
        return (bool) $this->get(self::APP_LOCK_ON_LAUNCH, true);
    }

    public function hasCompletedOnboarding(): bool
    {
        return $this->get(self::ONBOARDING_COMPLETED_AT) !== null;
    }
}
