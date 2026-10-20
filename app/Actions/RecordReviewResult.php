<?php

namespace App\Actions;

use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Models\ReviewFinding;
use App\Models\ReviewRun;
use Illuminate\Support\Facades\DB;

/**
 * Stores Claude's answer for a review run without duplicating findings from earlier runs.
 *
 * A finding Claude reports again keeps the user's decision, and previous findings Claude
 * says are gone are marked resolved.
 */
class RecordReviewResult
{
    /**
     * @param  array{summary?: string, verdict?: string, findings?: list<array<string, mixed>>, previous_findings?: list<array{id?: int, still_present?: bool}>}  $output
     */
    public function handle(ReviewRun $run, array $output): void
    {
        DB::transaction(function () use ($run, $output): void {
            $pullRequest = $run->pullRequest;
            $existing = $pullRequest->findings()->get()->keyBy('fingerprint');
            $newCount = 0;
            $repeatedCount = 0;
            $resolvedCount = 0;

            foreach ($output['findings'] ?? [] as $item) {
                $category = FindingCategory::tryFrom((string) ($item['category'] ?? '')) ?? FindingCategory::Other;
                $title = trim((string) ($item['title'] ?? ''));
                $path = filled($item['path'] ?? null) ? ltrim((string) $item['path'], './') : null;

                if ($title === '') {
                    continue;
                }

                $fingerprint = ReviewFinding::fingerprintFor($path, $category, $title);
                $attributes = [
                    'last_seen_review_run_id' => $run->id,
                    'severity' => FindingSeverity::tryFrom((string) ($item['severity'] ?? '')) ?? FindingSeverity::Medium,
                    'line' => is_numeric($item['line'] ?? null) ? (int) $item['line'] : null,
                    'body' => (string) ($item['body'] ?? ''),
                    'suggestion' => filled($item['suggestion'] ?? null) ? (string) $item['suggestion'] : null,
                    'resolved_at' => null,
                ];

                if ($finding = $existing->get($fingerprint)) {
                    $finding->update($attributes);
                    $repeatedCount++;

                    continue;
                }

                $existing->put($fingerprint, $pullRequest->findings()->create([
                    ...$attributes,
                    'review_run_id' => $run->id,
                    'fingerprint' => $fingerprint,
                    'category' => $category,
                    'path' => $path,
                    'title' => $title,
                    'status' => FindingStatus::New,
                ]));
                $newCount++;
            }

            $previousById = $existing->keyBy('id');

            foreach ($output['previous_findings'] ?? [] as $check) {
                $finding = $previousById->get($check['id'] ?? null);

                if ($finding === null || $finding->review_run_id === $run->id) {
                    continue;
                }

                if ($check['still_present'] ?? true) {
                    $finding->update(['last_seen_review_run_id' => $run->id, 'resolved_at' => null]);
                } elseif ($finding->resolved_at === null) {
                    $finding->update(['resolved_at' => now()]);
                    $resolvedCount++;
                }
            }

            $run->update([
                'summary' => $output['summary'] ?? null,
                'verdict' => $output['verdict'] ?? null,
                'new_findings_count' => $newCount,
                'repeated_findings_count' => $repeatedCount,
                'resolved_findings_count' => $resolvedCount,
            ]);
        });
    }
}
