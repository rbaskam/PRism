<?php

use App\Actions\RecordReviewResult;
use App\Enums\FindingCategory;
use App\Enums\FindingStatus;
use App\Models\ReviewFinding;
use App\Models\ReviewRun;

it('stores new findings from a review', function () {
    $run = ReviewRun::factory()->create();

    app(RecordReviewResult::class)->handle($run, [
        'summary' => 'Looks risky.',
        'verdict' => 'request_changes',
        'findings' => [[
            'category' => 'security',
            'severity' => 'high',
            'path' => './app/Http/Controllers/LoginController.php',
            'line' => 42,
            'title' => 'Password compared with ==',
            'body' => 'Use hash_equals.',
            'suggestion' => null,
        ]],
        'previous_findings' => [],
    ]);

    $finding = $run->pullRequest->findings()->sole();
    expect($finding)
        ->path->toBe('app/Http/Controllers/LoginController.php')
        ->line->toBe(42)
        ->status->toBe(FindingStatus::New)
        ->review_run_id->toBe($run->id);
    expect($run->fresh())
        ->summary->toBe('Looks risky.')
        ->verdict->toBe('request_changes')
        ->new_findings_count->toBe(1);
});

it('keeps the user decision when a re-run reports the same finding again', function () {
    $firstRun = ReviewRun::factory()->create();
    $finding = ReviewFinding::factory()->for($firstRun)->create([
        'pull_request_id' => $firstRun->pull_request_id,
        'path' => 'app/Models/User.php',
        'category' => FindingCategory::Tests,
        'title' => 'Missing tests for the new scope',
        'fingerprint' => ReviewFinding::fingerprintFor('app/Models/User.php', FindingCategory::Tests, 'Missing tests for the new scope'),
        'status' => FindingStatus::NotUseful,
    ]);
    $secondRun = ReviewRun::factory()->create(['pull_request_id' => $firstRun->pull_request_id]);

    app(RecordReviewResult::class)->handle($secondRun, [
        'summary' => 'Same as before.',
        'verdict' => 'comment',
        'findings' => [[
            'category' => 'tests',
            'severity' => 'low',
            'path' => 'app/Models/User.php',
            'line' => 12,
            'title' => 'Missing tests for the new scope!',
            'body' => 'Still no tests.',
            'suggestion' => null,
        ]],
        'previous_findings' => [],
    ]);

    expect(ReviewFinding::count())->toBe(1);
    expect($finding->fresh())
        ->status->toBe(FindingStatus::NotUseful)
        ->last_seen_review_run_id->toBe($secondRun->id)
        ->line->toBe(12);
    expect($secondRun->fresh())
        ->new_findings_count->toBe(0)
        ->repeated_findings_count->toBe(1);
});

it('marks earlier findings resolved when Claude says they are fixed', function () {
    $firstRun = ReviewRun::factory()->create();
    $fixed = ReviewFinding::factory()->for($firstRun)->create(['pull_request_id' => $firstRun->pull_request_id]);
    $stillThere = ReviewFinding::factory()->for($firstRun)->create(['pull_request_id' => $firstRun->pull_request_id]);
    $secondRun = ReviewRun::factory()->create(['pull_request_id' => $firstRun->pull_request_id]);

    app(RecordReviewResult::class)->handle($secondRun, [
        'summary' => 'Better.',
        'verdict' => 'comment',
        'findings' => [],
        'previous_findings' => [
            ['id' => $fixed->id, 'still_present' => false],
            ['id' => $stillThere->id, 'still_present' => true],
        ],
    ]);

    expect($fixed->fresh()->resolved_at)->not->toBeNull()
        ->and($stillThere->fresh()->resolved_at)->toBeNull()
        ->and($secondRun->fresh()->resolved_findings_count)->toBe(1);
});

it('ignores previous finding ids that belong to another pull request', function () {
    $otherFinding = ReviewFinding::factory()->create();
    $run = ReviewRun::factory()->create();

    app(RecordReviewResult::class)->handle($run, [
        'summary' => 'Fine.',
        'verdict' => 'approve',
        'findings' => [],
        'previous_findings' => [['id' => $otherFinding->id, 'still_present' => false]],
    ]);

    expect($otherFinding->fresh()->resolved_at)->toBeNull();
});

it('stores a finding once when Claude repeats it within the same review', function () {
    $run = ReviewRun::factory()->create();
    $finding = ['category' => 'bug', 'severity' => 'medium', 'path' => 'a.php', 'line' => 1, 'title' => 'Null check missing', 'body' => 'x', 'suggestion' => null];

    app(RecordReviewResult::class)->handle($run, [
        'summary' => 's',
        'verdict' => 'comment',
        'findings' => [$finding, $finding],
        'previous_findings' => [],
    ]);

    expect($run->pullRequest->findings()->count())->toBe(1);
});
