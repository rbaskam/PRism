<?php

use App\Models\PullRequest;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    onboardedAccount();
});

it('returns the text of a changed file by its blob SHA', function () {
    $pullRequest = PullRequest::factory()->create();
    $sha = sha1('app/Foo.php');
    Http::fake([
        "api.github.com/repos/{$pullRequest->repository->full_name}/git/blobs/{$sha}" => Http::response([
            'sha' => $sha,
            'size' => 12,
            'encoding' => 'base64',
            'content' => base64_encode("<?php\necho 1;\n"),
        ]),
    ]);

    $response = $this->getJson(route('pull-requests.file-contents.show', ['pullRequest' => $pullRequest, 'sha' => $sha]));

    $response->assertOk()->assertExactJson(['content' => "<?php\necho 1;\n"]);
});

it('says when a file is too large or not text', function (array $blob) {
    $pullRequest = PullRequest::factory()->create();
    $sha = sha1('logo.png');
    Http::fake(["api.github.com/repos/{$pullRequest->repository->full_name}/git/blobs/{$sha}" => Http::response(['sha' => $sha, ...$blob])]);

    $response = $this->getJson(route('pull-requests.file-contents.show', ['pullRequest' => $pullRequest, 'sha' => $sha]));

    $response->assertUnprocessable()->assertJsonPath('message', 'This file is too large or not text, so the lines around the changes can’t be shown.');
})->with([
    'too large' => [['size' => 2_000_000, 'encoding' => 'base64', 'content' => '']],
    'binary' => [['size' => 4, 'encoding' => 'base64', 'content' => base64_encode("\x89PNG\xff\xfe")]],
]);

it('only asks GitHub for a blob SHA', function () {
    $pullRequest = PullRequest::factory()->create();

    $this->getJson(route('pull-requests.file-contents.show', ['pullRequest' => $pullRequest, 'sha' => '../../user']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sha');
    Http::assertNothingSent();
});
