<?php

namespace App\Actions;

use App\Enums\GithubTokenSource;
use App\Jobs\PollNotifications;
use App\Jobs\SyncGithub;
use App\Models\GithubAccount;
use App\Models\GithubNotification;
use App\Models\PullRequest;
use App\Services\AppSettings;
use App\Services\Github\GithubClient;

/**
 * Verifies a GitHub token and stores it as the app's single connected account.
 */
class ConnectGithubAccount
{
    public function __construct(private readonly AppSettings $settings) {}

    /**
     * When the token belongs to a different GitHub account from the one connected before, even one disconnected
     * since, what belonged to that account is forgotten first. Once onboarding is done, a sync starts straight away so
     * the lists catch up with the account and its token.
     *
     * @return array{account: GithubAccount, switched_account: bool}
     */
    public function handle(string $token, GithubTokenSource $source): array
    {
        $github = new GithubClient($token);
        $authenticated = $github->authenticatedUser();
        $user = $authenticated['user'];
        $previousGithubId = $this->settings->get(AppSettings::GITHUB_ACCOUNT_ID) ?? GithubAccount::current()?->github_id;
        $switchedAccount = $previousGithubId !== null && (int) $previousGithubId !== (int) $user['id'];

        if ($switchedAccount) {
            $this->forgetPreviousAccount();
        }

        GithubAccount::query()->where('github_id', '!=', $user['id'])->delete();

        $account = GithubAccount::query()->updateOrCreate(['github_id' => $user['id']], [
            'login' => $user['login'],
            'name' => $user['name'] ?? null,
            'avatar_url' => $user['avatar_url'] ?? null,
            'token' => $token,
            'token_source' => $source,
            'scopes' => $authenticated['scopes'],
        ]);

        $account->update([
            'teams' => $github->teams(),
            'teams_synced_at' => now(),
        ]);

        $this->settings->set(AppSettings::GITHUB_ACCOUNT_ID, (int) $user['id']);

        if ($this->settings->hasCompletedOnboarding()) {
            SyncGithub::dispatch();
            PollNotifications::dispatch();
        }

        return ['account' => $account, 'switched_account' => $switchedAccount];
    }

    /**
     * Tell the user what connecting did.
     *
     * @param  array{account: GithubAccount, switched_account: bool}  $result
     */
    public function message(array $result): string
    {
        $login = $result['account']->login;

        if ($result['switched_account']) {
            return "Connected to GitHub as @{$login}. Notifications and review requests from the previous account were cleared.";
        }

        return $this->settings->hasCompletedOnboarding() ? "Reconnected to GitHub as @{$login}." : "Connected to GitHub as @{$login}.";
    }

    /**
     * Forget the previous account's GitHub notifications and how it was involved in each pull request: review
     * requests, its reviews, authorship and assignments. The next sync works these out again for the new account.
     * Tracked repositories, AI reviews and everything else made in the app stay.
     */
    private function forgetPreviousAccount(): void
    {
        GithubNotification::query()->delete();

        PullRequest::query()->update([
            'is_review_requested_from_me' => false,
            'is_review_requested_directly' => false,
            'requested_via_teams' => json_encode([]),
            'is_authored_by_me' => false,
            'is_assigned_to_me' => false,
            'my_review_state' => null,
            'my_review_submitted_at' => null,
            'my_review_commit_sha' => null,
        ]);
    }
}
