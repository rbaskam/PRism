<?php

namespace Tests\Browser\Support;

use App\Services\Github\GithubClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A stand-in for the GitHub API that browser tests talk to instead of the real thing. Describe the pull requests,
 * teams and notifications GitHub should report, call fake(), then sync so the app stores them like it would in real use.
 *
 * Unknown requests are refused, so a page that calls GitHub in a way the fake doesn't know fails its test.
 */
final class FakeGithub
{
    /**
     * Pull request nodes per "owner/name", shaped like GitHub's GraphQL API.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $pullRequests = [];

    /**
     * @var list<array{organization: string, slug: string, name: string}>
     */
    private array $teams = [];

    /**
     * Repositories outside the tracked ones that have a review request for the user.
     *
     * @var list<string>
     */
    private array $reviewRequestsElsewhere = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $notifications = [];

    /**
     * Changed files per "owner/name#number", shaped like GitHub's REST API.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private array $files = [];

    /**
     * Repositories the user can see, shaped like GitHub's REST API.
     *
     * @var list<array<string, mixed>>
     */
    private array $repositories = [];

    /**
     * Requests the fake didn't recognise, e.g. "GET /user/emails". BrowserTestCase fails the test when there are any.
     *
     * @var list<string>
     */
    public array $unhandledRequests = [];

    public function __construct(private readonly string $login = 'octocat') {}

    /**
     * A pull request node with sensible defaults, as GitHub's GraphQL API returns it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function pullRequest(int $number, string $title, array $overrides = []): array
    {
        return githubPullRequestNode(['number' => $number, 'title' => $title, ...$overrides]);
    }

    /**
     * Node fields for a review requested from a user, made on 1 October.
     *
     * @return array<string, mixed>
     */
    public static function requestedFrom(string $login): array
    {
        $reviewer = ['__typename' => 'User', 'login' => $login];

        return [
            'reviewRequests' => ['nodes' => [['requestedReviewer' => $reviewer]]],
            'reviewRequestedEvents' => ['nodes' => [['createdAt' => '2026-10-01T10:00:00Z', 'requestedReviewer' => $reviewer]]],
        ];
    }

    /**
     * Node fields for a review requested from a team, made on 1 October.
     *
     * @return array<string, mixed>
     */
    public static function requestedFromTeam(string $organization, string $slug): array
    {
        $team = ['__typename' => 'Team', 'slug' => $slug, 'name' => Str::headline($slug), 'organization' => ['login' => $organization]];

        return [
            'reviewRequests' => ['nodes' => [['requestedReviewer' => $team]]],
            'reviewRequestedEvents' => ['nodes' => [['createdAt' => '2026-10-01T10:00:00Z', 'requestedReviewer' => $team]]],
        ];
    }

    /**
     * Node fields for a review the user submitted on 2 October.
     *
     * @return array<string, mixed>
     */
    public static function reviewedBy(string $login, string $state, string $commit = 'headsha'): array
    {
        $review = ['author' => ['login' => $login], 'state' => $state, 'submittedAt' => '2026-10-02T09:00:00Z', 'commit' => ['oid' => $commit]];

        return [
            'latestOpinionatedReviews' => ['nodes' => $state === 'COMMENTED' ? [] : [$review]],
            'latestReviews' => ['nodes' => [$review]],
        ];
    }

    /**
     * A comment in a pull request's conversation, as GitHub's GraphQL API returns it.
     *
     * @return array<string, mixed>
     */
    public static function comment(string $login, string $body): array
    {
        return [
            'id' => 'IC_'.Str::random(8),
            'databaseId' => random_int(1000, 9999),
            'body' => $body,
            'bodyHTML' => '<p>'.e($body).'</p>',
            'createdAt' => '2026-10-03T10:00:00Z',
            'url' => 'https://github.com/acme/api/pull/1#issuecomment-1',
            'author' => ['login' => $login, 'avatarUrl' => null],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    public function withPullRequests(string $repository, array $nodes): self
    {
        foreach ($nodes as $node) {
            $this->pullRequests[$repository][$node['number']] = [...$node, 'url' => "https://github.com/{$repository}/pull/{$node['number']}"];
        }

        return $this;
    }

    public function withTeam(string $organization, string $slug): self
    {
        $this->teams[] = ['organization' => $organization, 'slug' => $slug, 'name' => Str::headline($slug)];

        return $this;
    }

    public function withReviewRequestIn(string $repository): self
    {
        $this->reviewRequestsElsewhere[] = $repository;

        return $this;
    }

    /**
     * Add an unread notification thread.
     */
    public function withNotification(string $id, string $reason, string $repository, int $number, string $title, string $updatedAt = '2026-10-13T09:00:00Z'): self
    {
        $this->notifications[] = [
            'id' => $id,
            'reason' => $reason,
            'unread' => true,
            'updated_at' => $updatedAt,
            'subject' => ['title' => $title, 'url' => GithubClient::BASE_URL."/repos/{$repository}/pulls/{$number}", 'type' => 'PullRequest'],
            'repository' => ['full_name' => $repository, 'html_url' => "https://github.com/{$repository}"],
        ];

        return $this;
    }

    /**
     * @param  list<array{filename: string, status?: string, additions?: int, deletions?: int, patch?: string}>  $files
     */
    public function withFiles(string $repository, int $number, array $files): self
    {
        $this->files["{$repository}#{$number}"] = array_map(fn (array $file): array => [
            'status' => 'modified',
            'additions' => 1,
            'deletions' => 0,
            'sha' => sha1($file['filename']),
            ...$file,
        ], $files);

        return $this;
    }

    public function withRepository(string $fullName, bool $isPrivate = true): self
    {
        [$owner, $name] = explode('/', $fullName);

        $this->repositories[] = [
            'id' => crc32($fullName),
            'owner' => ['login' => $owner],
            'name' => $name,
            'full_name' => $fullName,
            'html_url' => "https://github.com/{$fullName}",
            'default_branch' => 'main',
            'private' => $isPrivate,
            'archived' => false,
            'pushed_at' => '2026-10-10T10:00:00Z',
            'description' => null,
        ];

        return $this;
    }

    /**
     * Route every request to api.github.com through this fake.
     */
    public function fake(): self
    {
        Http::fake([GithubClient::BASE_URL.'/*' => fn (Request $request): PromiseInterface => $this->respond($request)]);
        app()->instance(self::class, $this);

        return $this;
    }

    private function respond(Request $request): PromiseInterface
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            $path === '/graphql' => $this->graphql($request),
            $path === '/user' => Http::response(
                ['id' => 583231, 'login' => $this->login, 'name' => 'The Octocat', 'avatar_url' => null],
                200,
                ['X-OAuth-Scopes' => 'repo, read:org, notifications'],
            ),
            $path === '/user/teams' => Http::response(array_map(fn (array $team): array => [
                'organization' => ['login' => $team['organization']],
                'slug' => $team['slug'],
                'name' => $team['name'],
            ], $this->teams)),
            $path === '/search/issues' => Http::response(['items' => array_map(fn (string $repository): array => [
                'repository_url' => GithubClient::BASE_URL."/repos/{$repository}",
            ], $this->reviewRequestsElsewhere)]),
            $path === '/notifications' && $request->method() === 'GET' => Http::response($this->notifications, 200, ['Last-Modified' => 'Tue, 13 Oct 2026 09:00:00 GMT']),
            $path === '/notifications' && $request->method() === 'PUT', str_starts_with($path, '/notifications/threads/') => Http::response(null, 205),
            $path === '/user/repos' => Http::response($this->repositories),
            $path === '/user/orgs' => Http::response([]),
            (bool) preg_match('#^/repos/(.+)/pulls/(\d+)/files$#', $path, $matches) => Http::response($this->files["{$matches[1]}#{$matches[2]}"] ?? []),
            (bool) preg_match('#^/repos/(.+)/pulls/(\d+)/reviews$#', $path, $matches) => $this->submitReview($matches[1], (int) $matches[2], $request),
            default => $this->unhandled($request, $path),
        };
    }

    private function unhandled(Request $request, string $path): PromiseInterface
    {
        $this->unhandledRequests[] = "{$request->method()} {$path}";

        return Http::response(['message' => "FakeGithub doesn't handle {$request->method()} {$path}"], 404);
    }

    private function graphql(Request $request): PromiseInterface
    {
        $query = (string) $request['query'];
        $variables = (array) $request['variables'];
        $repository = ($variables['owner'] ?? '').'/'.($variables['name'] ?? '');

        if (str_contains($query, 'pullRequests(states: OPEN')) {
            return Http::response(githubOpenPullRequestsResponse(array_values($this->pullRequests[$repository] ?? [])));
        }

        if (str_contains($query, 'viewerViewedState')) {
            return Http::response(['data' => ['repository' => ['pullRequest' => ['files' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'nodes' => [],
            ]]]]]);
        }

        if (str_contains($query, 'markFileAsViewed')) {
            return Http::response(['data' => ['markFileAsViewed' => ['clientMutationId' => null]]]);
        }

        if (str_contains($query, 'reviewThreads')) {
            $node = $this->pullRequests[$repository][$variables['number']] ?? null;

            return Http::response(['data' => ['repository' => ['pullRequest' => $node === null ? null : [
                'bodyHTML' => '',
                'reviewThreads' => ['nodes' => []],
                'conversation' => ['nodes' => []],
                'reviews' => ['nodes' => []],
                ...$node,
            ]]]]);
        }

        return $this->unhandled($request, '/graphql '.Str::of($query)->squish()->limit(60));
    }

    /**
     * Record a submitted review the way GitHub shows it straight afterwards: the review is there, but the
     * request it answered is still listed for a few seconds.
     */
    private function submitReview(string $repository, int $number, Request $request): PromiseInterface
    {
        $review = [
            'author' => ['login' => $this->login],
            'state' => match ($request['event']) {
                'APPROVE' => 'APPROVED',
                'REQUEST_CHANGES' => 'CHANGES_REQUESTED',
                default => 'COMMENTED',
            },
            'submittedAt' => now()->toIso8601ZuluString(),
            'commit' => ['oid' => $this->pullRequests[$repository][$number]['headRefOid'] ?? 'headsha'],
        ];

        if ($review['state'] !== 'COMMENTED') {
            $this->pullRequests[$repository][$number]['latestOpinionatedReviews']['nodes'] = [$review];
        }

        $this->pullRequests[$repository][$number]['latestReviews']['nodes'] = [$review];

        return Http::response(['id' => 1, 'html_url' => "https://github.com/{$repository}/pull/{$number}#pullrequestreview-1"]);
    }

    /**
     * Requests sent to the fake for one path, e.g. "/repos/acme/api/pulls/7/reviews".
     *
     * @return list<array<string, mixed>>
     */
    public function sent(string $method, string $path): array
    {
        return Http::recorded(fn (Request $request): bool => $request->method() === $method && parse_url($request->url(), PHP_URL_PATH) === $path)
            ->map(fn (array $pair): array => Arr::wrap($pair[0]->data()))
            ->values()
            ->all();
    }
}
