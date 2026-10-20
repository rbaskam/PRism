<?php

namespace App\Http\Controllers;

use App\Models\GithubAccount;
use App\Models\Repository;
use App\Services\Github\GithubClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lists the repositories the connected account can access so the user can choose which to track.
 */
class AvailableRepositoryController extends Controller
{
    public function index(Request $request, GithubClient $github): JsonResponse
    {
        $account = GithubAccount::current();
        $cacheKey = "github.repositories.{$account->github_id}";

        if ($request->boolean('refresh')) {
            Cache::forget($cacheKey);
        }

        try {
            $listing = Cache::remember($cacheKey, now()->addMinutes(2), fn (): array => $github->repositories());
        } catch (RequestException|ConnectionException $exception) {
            return response()->json(['message' => GithubClient::describeFailure($exception)], Response::HTTP_BAD_GATEWAY);
        }

        $tracked = Repository::query()->tracked()->pluck('github_id')->all();

        return response()->json([
            'repositories' => array_map(fn (array $repository): array => [
                ...$repository,
                'is_tracked' => in_array($repository['github_id'], $tracked, true),
                'has_review_requests' => in_array($repository['full_name'], $account->untracked_review_requests ?? [], true),
            ], $listing['repositories']),
            'warnings' => $this->warnings($account, $listing['sso_organizations']),
        ]);
    }

    /**
     * Explain why repositories may be missing from the list, linking straight to the page that fixes it.
     *
     * @param  list<array{organization: string, url: ?string}>  $ssoOrganizations
     * @return list<array{key: string, message: string, url: string}>
     */
    private function warnings(GithubAccount $account, array $ssoOrganizations): array
    {
        $isClassicToken = str_starts_with($account->token, 'ghp_');

        $warnings = array_map(fn (array $sso): array => $isClassicToken
            ? [
                'key' => "sso:{$sso['organization']}",
                'message' => "{$sso['organization']} uses single sign-on, so its repositories are hidden until you authorise your token for it. On your tokens page, click \"Configure SSO\" next to the token you gave PRism and authorise {$sso['organization']}, then refresh this list.",
                'url' => $sso['url'] ?? 'https://github.com/settings/tokens',
            ]
            : [
                'key' => "sso:{$sso['organization']}",
                'message' => "{$sso['organization']} uses single sign-on, so its repositories are hidden until you sign in to it. Sign in to {$sso['organization']}'s single sign-on, then reconnect GitHub in PRism (Settings → GitHub → Reconnect).",
                'url' => $sso['url'] ?? "https://github.com/orgs/{$sso['organization']}/sso",
            ], $ssoOrganizations);

        if (str_starts_with($account->token, 'github_pat_')) {
            $warnings[] = [
                'key' => 'fine-grained-token',
                'message' => 'You connected with a fine-grained token, which only sees the repositories of the one owner it was created for. To see organisation repositories, create a classic token with the repo, read:org and notifications scopes, or connect with the GitHub CLI login.',
                'url' => 'https://github.com/settings/tokens/new?scopes=repo,read:org,notifications&description=PRism',
            ];
        }

        return $warnings;
    }
}
