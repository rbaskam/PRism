<?php

use App\Actions\LoadPullRequestFiles;
use App\Models\PullRequest;
use App\Models\PullRequestFileView;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('marks a file as viewed in the app and on GitHub', function () {
    onboardedAccount();
    $pullRequest = PullRequest::factory()->create(['node_id' => 'PR_abc']);
    Http::fake(['api.github.com/graphql' => Http::response(['data' => ['markFileAsViewed' => ['clientMutationId' => null]]])]);

    $response = $this->putJson(route('pull-requests.file-views.update', $pullRequest), ['path' => 'app/Foo.php', 'viewed' => true, 'file_sha' => 'blob1']);

    $response->assertOk()->assertJson(['viewed' => true, 'synced_to_github' => true]);
    expect($pullRequest->fileViews()->sole())->path->toBe('app/Foo.php')->file_sha->toBe('blob1');
    Http::assertSent(fn (Request $request): bool => str_contains($request['query'], 'markFileAsViewed')
        && $request['variables'] === ['pullRequestId' => 'PR_abc', 'path' => 'app/Foo.php']);
});

it('keeps the viewed state in the app when GitHub cannot be reached', function () {
    onboardedAccount();
    $pullRequest = PullRequest::factory()->create();
    PullRequestFileView::create(['pull_request_id' => $pullRequest->id, 'path' => 'app/Foo.php', 'file_sha' => 'blob1', 'viewed_at' => now()]);
    Http::fake(['api.github.com/graphql' => Http::response(['message' => 'Server Error'], 500)]);

    $response = $this->putJson(route('pull-requests.file-views.update', $pullRequest), ['path' => 'app/Foo.php', 'viewed' => false]);

    $response->assertOk()->assertJson(['viewed' => false, 'synced_to_github' => false]);
    expect($pullRequest->fileViews()->count())->toBe(0);
});

it('works out which files the user has viewed, including on github.com', function () {
    onboardedAccount();
    $pullRequest = PullRequest::factory()->create(['number' => 5]);
    $repository = $pullRequest->repository;
    PullRequestFileView::create(['pull_request_id' => $pullRequest->id, 'path' => 'viewed.php', 'file_sha' => 'same', 'viewed_at' => now()]);
    PullRequestFileView::create(['pull_request_id' => $pullRequest->id, 'path' => 'edited-since.php', 'file_sha' => 'old', 'viewed_at' => now()]);
    $file = fn (string $path, string $sha): array => ['filename' => $path, 'status' => 'modified', 'additions' => 1, 'deletions' => 0, 'sha' => $sha, 'patch' => '@@ -1 +1 @@'];
    Http::fake([
        "api.github.com/repos/{$repository->full_name}/pulls/5/files*" => Http::response([
            $file('viewed.php', 'same'),
            $file('edited-since.php', 'new'),
            $file('viewed-on-web.php', 'x'),
            $file('untouched.php', 'y'),
        ]),
        'api.github.com/graphql' => Http::response(['data' => ['repository' => ['pullRequest' => ['files' => [
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            'nodes' => [
                ['path' => 'viewed.php', 'viewerViewedState' => 'VIEWED'],
                ['path' => 'edited-since.php', 'viewerViewedState' => 'DISMISSED'],
                ['path' => 'viewed-on-web.php', 'viewerViewedState' => 'VIEWED'],
                ['path' => 'untouched.php', 'viewerViewedState' => 'UNVIEWED'],
            ],
        ]]]]]),
    ]);

    $files = collect(app(LoadPullRequestFiles::class)->handle($pullRequest))->keyBy('path');

    expect($files->map(fn (array $file): array => [$file['is_viewed'], $file['changed_since_viewed']])->all())->toBe([
        'viewed.php' => [true, false],
        'edited-since.php' => [false, true],
        'viewed-on-web.php' => [true, false],
        'untouched.php' => [false, false],
    ]);
});

it('reuses the saved diff until new commits are pushed', function () {
    Http::preventStrayRequests();
    onboardedAccount();
    $pullRequest = PullRequest::factory()->create(['head_sha' => 'abc']);
    $pullRequest->update([
        'files_head_sha' => 'abc',
        'files_snapshot' => ['files' => [['path' => 'app/Foo.php', 'previous_path' => null, 'status' => 'modified', 'additions' => 1, 'deletions' => 0, 'sha' => 'blob', 'patch' => '@@ -1 +1 @@']], 'github_viewed_states' => []],
    ]);

    $files = app(LoadPullRequestFiles::class)->handle($pullRequest);

    expect($files[0]['path'])->toBe('app/Foo.php');
    Http::assertNothingSent();
});

it('fetches the diff again after new commits', function () {
    Http::preventStrayRequests();
    onboardedAccount();
    $pullRequest = PullRequest::factory()->create(['number' => 9, 'head_sha' => 'new']);
    $pullRequest->update(['files_head_sha' => 'old', 'files_snapshot' => ['files' => [], 'github_viewed_states' => []]]);
    Http::fake([
        "api.github.com/repos/{$pullRequest->repository->full_name}/pulls/9/files*" => Http::response([
            ['filename' => 'app/New.php', 'status' => 'added', 'additions' => 3, 'deletions' => 0, 'sha' => 'b', 'patch' => '@@ -0,0 +1,3 @@'],
        ]),
        'api.github.com/graphql' => Http::response(['data' => ['repository' => ['pullRequest' => ['files' => [
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'nodes' => [],
        ]]]]]),
    ]);

    $files = app(LoadPullRequestFiles::class)->handle($pullRequest);

    expect($files[0]['path'])->toBe('app/New.php')
        ->and($pullRequest->fresh()->files_head_sha)->toBe('new');
});
