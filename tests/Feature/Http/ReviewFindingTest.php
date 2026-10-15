<?php

use App\Enums\FindingStatus;
use App\Models\ReviewFinding;
use App\Services\AppSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('records the user decision on a finding', function () {
    onboardedAccount();
    $finding = ReviewFinding::factory()->create();

    $response = $this->patch(route('findings.update', $finding), ['status' => 'not_blocker']);

    $response->assertRedirect();
    expect($finding->fresh()->status)->toBe(FindingStatus::NotBlocker);
});

it('rejects an unknown finding status', function () {
    onboardedAccount();
    $finding = ReviewFinding::factory()->create();

    $response = $this->patch(route('findings.update', $finding), ['status' => 'ignored']);

    $response->assertSessionHasErrors('status');
    expect($finding->fresh()->status)->toBe(FindingStatus::New);
});

it('only bulk updates findings that belong to the pull request', function () {
    onboardedAccount();
    $finding = ReviewFinding::factory()->create();
    $otherFinding = ReviewFinding::factory()->create();

    $response = $this->patch(route('pull-requests.findings.bulk-update', $finding->pull_request_id), [
        'finding_ids' => [$finding->id, $otherFinding->id],
        'status' => 'read',
    ]);

    $response->assertSessionHasErrors('finding_ids.1');
    expect($finding->fresh()->status)->toBe(FindingStatus::New)
        ->and($otherFinding->fresh()->status)->toBe(FindingStatus::New);
});

it('refuses to post findings to GitHub while posting is turned off', function () {
    onboardedAccount();
    $finding = ReviewFinding::factory()->create();

    $response = $this->post(route('findings.post', $finding), ['body' => 'Please fix']);

    $response->assertSessionHas('error', 'Posting findings is turned off in Settings → PR review.');
    expect($finding->fresh()->posted_at)->toBeNull();
});

it('posts a finding on its line in the diff', function () {
    onboardedAccount();
    app(AppSettings::class)->set(AppSettings::ALLOW_POSTING_FINDINGS, true);
    $finding = ReviewFinding::factory()->create(['path' => 'app/Foo.php', 'line' => 10]);
    $pullRequest = $finding->pullRequest;
    Http::fake([
        "api.github.com/repos/{$pullRequest->repository->full_name}/pulls/{$pullRequest->number}/comments" => Http::response(['id' => 777, 'html_url' => 'https://github.com/c/777'], 201),
    ]);

    $response = $this->post(route('findings.post', $finding), ['body' => 'Edited by me']);

    $response->assertSessionHas('success');
    expect($finding->fresh())
        ->github_comment_id->toBe(777)
        ->github_comment_url->toBe('https://github.com/c/777')
        ->status->toBe(FindingStatus::Read)
        ->posted_at->not->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request['body'] === 'Edited by me'
        && $request['path'] === 'app/Foo.php'
        && $request['line'] === 10
        && $request['commit_id'] === $pullRequest->head_sha);
});

it('falls back to a conversation comment when the line is outside the diff', function () {
    onboardedAccount();
    app(AppSettings::class)->set(AppSettings::ALLOW_POSTING_FINDINGS, true);
    $finding = ReviewFinding::factory()->create(['path' => 'app/Foo.php', 'line' => 999]);
    $pullRequest = $finding->pullRequest;
    $repository = $pullRequest->repository->full_name;
    Http::fake([
        "api.github.com/repos/{$repository}/pulls/{$pullRequest->number}/comments" => Http::response(['message' => 'Validation Failed'], 422),
        "api.github.com/repos/{$repository}/issues/{$pullRequest->number}/comments" => Http::response(['id' => 888, 'html_url' => 'https://github.com/c/888'], 201),
    ]);

    $this->post(route('findings.post', $finding), ['body' => 'Edited by me']);

    expect($finding->fresh()->github_comment_id)->toBe(888);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/issues/')
        && $request['body'] === "`app/Foo.php:999`\n\nEdited by me");
});
