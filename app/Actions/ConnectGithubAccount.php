<?php

namespace App\Actions;

use App\Enums\GithubTokenSource;
use App\Models\GithubAccount;
use App\Services\Github\GithubClient;

/**
 * Verifies a GitHub token and stores it as the app's single connected account.
 */
class ConnectGithubAccount
{
    public function handle(string $token, GithubTokenSource $source): GithubAccount
    {
        $github = new GithubClient($token);
        $authenticated = $github->authenticatedUser();
        $user = $authenticated['user'];

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

        return $account;
    }
}
