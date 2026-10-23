<?php

namespace App\Services\Github;

use App\Enums\SubmitReviewEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A thin wrapper around the GitHub REST and GraphQL APIs for the connected account.
 */
class GithubClient
{
    public const BASE_URL = 'https://api.github.com';

    private const MAX_REPOSITORY_PAGES = 10;

    /**
     * GitHub returns at most 3,000 files for a pull request.
     */
    private const MAX_PULL_REQUEST_FILE_PAGES = 30;

    public function __construct(private readonly string $token) {}

    /**
     * Get the authenticated user and the OAuth scopes granted to the token.
     *
     * @return array{user: array<string, mixed>, scopes: list<string>}
     */
    public function authenticatedUser(): array
    {
        $response = $this->readRequest()->get('/user')->throw();

        return [
            'user' => $response->json(),
            'scopes' => array_values(array_filter(array_map('trim', explode(',', $response->header('X-OAuth-Scopes'))))),
        ];
    }

    /**
     * Get the teams the authenticated user belongs to.
     *
     * @return list<array{organization: string, slug: string, name: string}>
     */
    public function teams(): array
    {
        $response = $this->readRequest()->get('/user/teams', ['per_page' => 100]);

        if ($response->forbidden()) {
            return [];
        }

        /** @var list<array{slug: string, name: string, organization: array{login: string}}> $teams */
        $teams = $response->throw()->json();

        return collect($teams)
            ->map(fn (array $team): array => [
                'organization' => $team['organization']['login'],
                'slug' => $team['slug'],
                'name' => $team['name'],
            ])
            ->values()
            ->all();
    }

    /**
     * Get the repositories the authenticated user can access, most recently pushed first.
     *
     * Organisation repositories are also listed per organisation, because /user/repos leaves out
     * repositories the token can't see. Organisations that use SAML single sign-on and haven't
     * authorised the token are returned in sso_organizations so the user can be told how to fix it.
     *
     * @return array{repositories: list<array{github_id: int, owner: string, name: string, full_name: string, html_url: string, default_branch: ?string, is_private: bool, pushed_at: ?string, description: ?string}>, sso_organizations: list<array{organization: string, url: ?string}>}
     */
    public function repositories(): array
    {
        /** @var array<int, array{github_id: int, owner: string, name: string, full_name: string, html_url: string, default_branch: ?string, is_private: bool, pushed_at: ?string, description: ?string}> $repositories */
        $repositories = [];
        $ssoOrganizationIds = [];
        $ssoOrganizations = [];

        $this->collectRepositories('/user/repos', [
            'sort' => 'pushed',
            'affiliation' => 'owner,collaborator,organization_member',
        ], $repositories, $ssoOrganizationIds);

        $organizations = $this->readRequest()->get('/user/orgs', ['per_page' => 100]);

        foreach ($organizations->successful() ? $organizations->json() : [] as $organization) {
            $response = $this->collectRepositories("/orgs/{$organization['login']}/repos", ['type' => 'all', 'sort' => 'pushed'], $repositories, $ssoOrganizationIds);

            if ($response->forbidden() && $response->header('X-GitHub-SSO')) {
                $ssoOrganizations[$organization['login']] = $this->ssoUrl($response->header('X-GitHub-SSO'));
            }
        }

        foreach (array_unique($ssoOrganizationIds) as $organizationId) {
            $login = $this->readRequest()->get("/organizations/{$organizationId}")->json('login') ?? "an organisation (ID {$organizationId})";
            $ssoOrganizations[$login] ??= null;
        }

        usort($repositories, fn (array $a, array $b): int => strcmp((string) $b['pushed_at'], (string) $a['pushed_at']));

        return [
            'repositories' => $repositories,
            'sso_organizations' => collect($ssoOrganizations)
                ->map(fn (?string $url, string $organization): array => ['organization' => $organization, 'url' => $url])
                ->values()
                ->all(),
        ];
    }

    /**
     * Page through a repository listing, keyed by GitHub id so repositories are not duplicated.
     *
     * @param  array<string, mixed>  $query
     * @param  array<int, array{github_id: int, owner: string, name: string, full_name: string, html_url: string, default_branch: ?string, is_private: bool, pushed_at: ?string, description: ?string}>  $repositories
     * @param  list<string>  $ssoOrganizationIds
     */
    private function collectRepositories(string $url, array $query, array &$repositories, array &$ssoOrganizationIds): Response
    {
        $page = 1;

        do {
            $response = $this->readRequest()->get($url, [...$query, 'per_page' => 100, 'page' => $page]);

            if (! $response->successful()) {
                return $response;
            }

            // e.g. "partial-results; organizations=21955855,20582480"
            if (preg_match('/organizations=([\d,]+)/', (string) $response->header('X-GitHub-SSO'), $matches)) {
                array_push($ssoOrganizationIds, ...explode(',', $matches[1]));
            }

            $batch = $response->json();

            foreach ($batch as $repository) {
                if ($repository['archived'] ?? false) {
                    continue;
                }

                $repositories[(int) $repository['id']] = [
                    'github_id' => (int) $repository['id'],
                    'owner' => (string) $repository['owner']['login'],
                    'name' => (string) $repository['name'],
                    'full_name' => (string) $repository['full_name'],
                    'html_url' => (string) $repository['html_url'],
                    'default_branch' => isset($repository['default_branch']) ? (string) $repository['default_branch'] : null,
                    'is_private' => (bool) $repository['private'],
                    'pushed_at' => isset($repository['pushed_at']) ? (string) $repository['pushed_at'] : null,
                    'description' => isset($repository['description']) ? (string) $repository['description'] : null,
                ];
            }

            if (count($batch) < 100) {
                break;
            }
        } while (++$page <= self::MAX_REPOSITORY_PAGES);

        return $response;
    }

    /**
     * Pull the authorisation link out of an "X-GitHub-SSO: required; url=…" header.
     */
    private function ssoUrl(string $header): ?string
    {
        return preg_match('/url=(\S+)/', $header, $matches) ? $matches[1] : null;
    }

    /**
     * Get one repository's details.
     *
     * @return array{github_id: int, owner: string, name: string, full_name: string, html_url: string, default_branch: ?string, is_private: bool}
     */
    public function repository(string $fullName): array
    {
        $repository = $this->readRequest()->get("/repos/{$fullName}")->throw()->json();

        return [
            'github_id' => (int) $repository['id'],
            'owner' => (string) $repository['owner']['login'],
            'name' => (string) $repository['name'],
            'full_name' => (string) $repository['full_name'],
            'html_url' => (string) $repository['html_url'],
            'default_branch' => isset($repository['default_branch']) ? (string) $repository['default_branch'] : null,
            'is_private' => (bool) $repository['private'],
        ];
    }

    /**
     * Get the "owner/name" of every repository with an open pull request awaiting the user's review.
     *
     * @return list<string>
     */
    public function repositoriesWithReviewRequests(): array
    {
        $items = $this->readRequest()->get('/search/issues', [
            'q' => 'is:pr is:open review-requested:@me archived:false',
            'per_page' => 100,
        ])->throw()->json('items', []);

        /** @var list<array{repository_url: string}> $items */
        return collect($items)
            ->map(fn (array $item): string => str($item['repository_url'])->after('/repos/')->toString())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Get every open pull request in a repository.
     *
     * @return list<array<string, mixed>>
     */
    public function openPullRequests(string $owner, string $name): array
    {
        $pullRequests = [];
        $cursor = null;

        do {
            $connection = $this->graphql(GraphqlQueries::OPEN_PULL_REQUESTS, [
                'owner' => $owner,
                'name' => $name,
                'cursor' => $cursor,
            ])['repository']['pullRequests'];

            array_push($pullRequests, ...$connection['nodes']);

            $cursor = $connection['pageInfo']['endCursor'];
        } while ($connection['pageInfo']['hasNextPage']);

        return $pullRequests;
    }

    /**
     * Get a single pull request including its description, review threads and conversation.
     *
     * @return array<string, mixed>
     */
    public function pullRequest(string $owner, string $name, int $number): array
    {
        return $this->graphql(GraphqlQueries::PULL_REQUEST, [
            'owner' => $owner,
            'name' => $name,
            'number' => $number,
        ])['repository']['pullRequest'];
    }

    /**
     * Get the files changed in a pull request, including each file's patch when GitHub provides one.
     *
     * @return list<array{path: string, previous_path: ?string, status: string, additions: int, deletions: int, sha: ?string, patch: ?string}>
     */
    public function pullRequestFiles(string $repository, int $number): array
    {
        $files = [];

        for ($page = 1; $page <= self::MAX_PULL_REQUEST_FILE_PAGES; $page++) {
            $batch = $this->readRequest()
                ->get("/repos/{$repository}/pulls/{$number}/files", ['per_page' => 100, 'page' => $page])
                ->throw()
                ->json();

            foreach ($batch as $file) {
                $files[] = [
                    'path' => $file['filename'],
                    'previous_path' => $file['previous_filename'] ?? null,
                    'status' => $file['status'],
                    'additions' => $file['additions'],
                    'deletions' => $file['deletions'],
                    'sha' => $file['sha'] ?? null,
                    'patch' => $file['patch'] ?? null,
                ];
            }

            if (count($batch) < 100) {
                break;
            }
        }

        return $files;
    }

    /**
     * Get the viewed state GitHub stores for the user on each file: VIEWED, UNVIEWED or DISMISSED (changed since viewed).
     *
     * @return array<string, string>
     */
    public function fileViewedStates(string $owner, string $name, int $number): array
    {
        $states = [];
        $cursor = null;

        do {
            $connection = $this->graphql(GraphqlQueries::FILE_VIEWED_STATES, [
                'owner' => $owner,
                'name' => $name,
                'number' => $number,
                'cursor' => $cursor,
            ])['repository']['pullRequest']['files'];

            foreach ($connection['nodes'] ?? [] as $file) {
                $states[$file['path']] = $file['viewerViewedState'];
            }

            $cursor = $connection['pageInfo']['endCursor'] ?? null;
        } while ($connection['pageInfo']['hasNextPage'] ?? false);

        return $states;
    }

    /**
     * Mark a file as viewed (or not) for the user on GitHub. This is private to the user.
     */
    public function setFileViewed(string $pullRequestNodeId, string $path, bool $viewed): void
    {
        $this->graphql($viewed ? GraphqlQueries::MARK_FILE_AS_VIEWED : GraphqlQueries::UNMARK_FILE_AS_VIEWED, [
            'pullRequestId' => $pullRequestNodeId,
            'path' => $path,
        ]);
    }

    /**
     * Get the user's recent notification threads.
     *
     * @return array{not_modified: bool, last_modified: ?string, notifications: list<array<string, mixed>>}
     */
    public function notifications(?string $lastModified = null): array
    {
        $request = $this->readRequest();

        if ($lastModified !== null) {
            $request = $request->withHeaders(['If-Modified-Since' => $lastModified]);
        }

        $response = $request->get('/notifications', [
            'all' => 'true',
            'per_page' => 50,
            'since' => now()->subDays(14)->toIso8601ZuluString(),
        ]);

        if ($response->status() === 304) {
            return ['not_modified' => true, 'last_modified' => $lastModified, 'notifications' => []];
        }

        return [
            'not_modified' => false,
            'last_modified' => $response->throw()->header('Last-Modified') ?: null,
            'notifications' => $response->json(),
        ];
    }

    public function markNotificationAsRead(string $threadId): void
    {
        // Sent without a body: patch() would send "[]", which GitHub answers with a 500.
        $this->request()->send('PATCH', "/notifications/threads/{$threadId}")->throw();
    }

    public function markAllNotificationsAsRead(): void
    {
        $this->request()->put('/notifications', ['read' => true])->throw();
    }

    /**
     * Submit a review, optionally with inline comments on lines in the diff.
     *
     * @param  list<array{path: string, line: int, body: string}>  $comments
     * @return array<string, mixed>
     */
    public function submitReview(string $repository, int $number, SubmitReviewEvent $event, ?string $body, array $comments = []): array
    {
        $payload = ['event' => $event->value];

        if (filled($body)) {
            $payload['body'] = $body;
        }

        if ($comments !== []) {
            $payload['comments'] = array_map(fn (array $comment): array => [...$comment, 'side' => 'RIGHT'], $comments);
        }

        return $this->request()->post("/repos/{$repository}/pulls/{$number}/reviews", $payload)->throw()->json();
    }

    /**
     * Open a pull request.
     *
     * @return array<string, mixed>
     */
    public function createPullRequest(string $repository, string $title, string $head, string $base, string $body): array
    {
        return $this->request()->post("/repos/{$repository}/pulls", [
            'title' => $title,
            'head' => $head,
            'base' => $base,
            'body' => $body,
        ])->throw()->json();
    }

    /**
     * Comment on a line in the pull request diff, or on the lines from $startLine to $line. $side is RIGHT for the
     * new version of the file and LEFT for removed lines.
     *
     * @return array<string, mixed>
     */
    public function createReviewComment(string $repository, int $number, string $commitSha, string $path, int $line, string $body, string $side = 'RIGHT', ?int $startLine = null): array
    {
        $payload = [
            'body' => $body,
            'commit_id' => $commitSha,
            'path' => $path,
            'line' => $line,
            'side' => $side,
        ];

        if ($startLine !== null && $startLine < $line) {
            $payload['start_line'] = $startLine;
            $payload['start_side'] = $side;
        }

        return $this->request()->post("/repos/{$repository}/pulls/{$number}/comments", $payload)->throw()->json();
    }

    /**
     * Add a comment to the pull request conversation.
     *
     * @return array<string, mixed>
     */
    public function createIssueComment(string $repository, int $number, string $body): array
    {
        return $this->request()->post("/repos/{$repository}/issues/{$number}/comments", [
            'body' => $body,
        ])->throw()->json();
    }

    /**
     * Reply to a comment in a review thread.
     *
     * @return array<string, mixed>
     */
    public function replyToReviewComment(string $repository, int $number, int $commentId, string $body): array
    {
        return $this->request()->post("/repos/{$repository}/pulls/{$number}/comments/{$commentId}/replies", [
            'body' => $body,
        ])->throw()->json();
    }

    public function resolveReviewThread(string $threadId): void
    {
        $this->graphql(GraphqlQueries::RESOLVE_REVIEW_THREAD, ['threadId' => $threadId]);
    }

    /**
     * Run a GraphQL query and return its data.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     *
     * @throws GithubException
     */
    public function graphql(string $query, array $variables = []): array
    {
        $response = $this->readRequest()->post('/graphql', [
            'query' => $query,
            'variables' => $variables,
        ])->throw();

        if ($errors = $response->json('errors')) {
            throw new GithubException(collect((array) $errors)->pluck('message')->implode(' '));
        }

        return $response->json('data');
    }

    /**
     * Get a short human readable reason a GitHub request failed.
     */
    public static function describeFailure(Throwable $exception): string
    {
        if ($exception instanceof RequestException) {
            $message = $exception->response->json('message') ?? $exception->response->reason();
            $details = collect((array) $exception->response->json('errors', []))
                ->map(fn (mixed $error): string => is_array($error) ? ($error['message'] ?? json_encode($error)) : (string) $error)
                ->implode(' ');

            return trim("GitHub returned {$exception->response->status()}: {$message} {$details}");
        }

        if ($exception instanceof ConnectionException) {
            return 'Could not reach GitHub. Check your internet connection.';
        }

        return $exception->getMessage();
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken($this->token)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->connectTimeout(5)
            ->timeout(30);
    }

    /**
     * A request for reading data, retried when GitHub is briefly unavailable.
     */
    private function readRequest(): PendingRequest
    {
        return $this->request()->retry(
            [200, 1000],
            // $exception is null for responses that are neither successful nor an error, such as 304 Not Modified.
            when: fn (?Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError()),
            throw: false,
        );
    }

    /**
     * Determine whether a failed request was rejected because the diff position is invalid.
     */
    public static function isUnprocessable(Throwable $exception): bool
    {
        return $exception instanceof RequestException && $exception->response->status() === 422;
    }

    /**
     * Determine whether a response represents an expired or revoked token.
     */
    public static function isUnauthorized(Response|Throwable $subject): bool
    {
        $response = $subject instanceof RequestException ? $subject->response : $subject;

        return $response instanceof Response && $response->unauthorized();
    }
}
