<?php

namespace App\Services\Claude;

use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Models\PullRequest;
use App\Models\ReviewFinding;
use App\Services\AppSettings;
use Illuminate\Support\Collection;

/**
 * Builds what Claude is told when reviewing a pull request: the user's prompt template,
 * the app's ground rules, and the findings from earlier runs so nothing is reported twice.
 */
class PullRequestReviewPrompt
{
    public function __construct(private readonly AppSettings $settings) {}

    /**
     * Render the user's review prompt template for a pull request.
     */
    public function prompt(PullRequest $pullRequest): string
    {
        return strtr($this->settings->reviewPrompt(), [
            '{number}' => (string) $pullRequest->number,
            '{repository}' => $pullRequest->repository->full_name,
            '{title}' => $pullRequest->title,
            '{author}' => (string) $pullRequest->author_login,
            '{head}' => (string) $pullRequest->head_ref,
            '{base}' => (string) $pullRequest->base_ref,
            '{url}' => $pullRequest->html_url,
        ]);
    }

    /**
     * @param  Collection<int, ReviewFinding>  $previousFindings
     */
    public function systemPrompt(PullRequest $pullRequest, Collection $previousFindings, bool $hasLocalCheckout): string
    {
        $repository = $pullRequest->repository->full_name;
        $number = $pullRequest->number;

        $lines = [
            "You are reviewing GitHub pull request {$repository}#{$number} for a developer using a desktop review app.",
            '',
            'Ground rules:',
            '- This review is read-only. Never comment on, approve, edit, push to or otherwise change the pull request or the repository. The developer decides what gets posted.',
            "- Read the pull request with `gh pr view {$number} --repo {$repository}` and `gh pr diff {$number} --repo {$repository}`. Always pass `--repo {$repository}`.",
            $hasLocalCheckout
                ? '- The working directory is a checkout of the pull request at its head commit. Read the changed files in full, grep for callers and use git log/show/diff for context.'
                : '- There is no local checkout, so work from the diff and `gh pr view` output.',
            '- Report each distinct problem once. Use the file path relative to the repository root and the line number on the new side of the diff, or null for general findings.',
            '- category is one of: '.implode(', ', array_column(FindingCategory::cases(), 'value')).'. severity is one of: '.implode(', ', array_column(FindingSeverity::cases(), 'value')).'.',
            '- Keep body short and specific. Put a concrete fix in suggestion, or null if there is none.',
            '- verdict: approve when nothing should block merging, request_changes when something must be fixed first, otherwise comment.',
        ];

        if ($previousFindings->isEmpty()) {
            $lines[] = '- previous_findings must be an empty array.';

            return implode("\n", $lines);
        }

        $previous = $previousFindings->map(fn (ReviewFinding $finding): array => [
            'id' => $finding->id,
            'category' => $finding->category->value,
            'severity' => $finding->severity->value,
            'path' => $finding->path,
            'line' => $finding->line,
            'title' => $finding->title,
            'developer_decision' => $finding->status->label(),
            'posted_to_pull_request' => $finding->isPosted(),
        ])->values();

        array_push(
            $lines,
            '',
            'This pull request has been reviewed before. These findings were already reported:',
            json_encode($previous, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            '',
            '- Do NOT report any of these again in findings, even reworded or on a different line.',
            '- Re-check every one of them against the current diff and list each in previous_findings with still_present true if the problem remains, or false if it has been fixed.',
            '- The developer marked some as "Not useful" or "Not a blocker". Respect that decision and do not raise the same point again.',
        );

        return implode("\n", $lines);
    }

    /**
     * The JSON schema Claude's answer must match.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string', 'description' => 'Two or three sentences on the overall state of the pull request.'],
                'verdict' => ['type' => 'string', 'enum' => ['approve', 'request_changes', 'comment']],
                'findings' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'category' => ['type' => 'string', 'enum' => array_column(FindingCategory::cases(), 'value')],
                            'severity' => ['type' => 'string', 'enum' => array_column(FindingSeverity::cases(), 'value')],
                            'path' => ['type' => ['string', 'null']],
                            'line' => ['type' => ['integer', 'null']],
                            'title' => ['type' => 'string'],
                            'body' => ['type' => 'string'],
                            'suggestion' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['category', 'severity', 'path', 'line', 'title', 'body', 'suggestion'],
                    ],
                ],
                'previous_findings' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'still_present' => ['type' => 'boolean'],
                        ],
                        'required' => ['id', 'still_present'],
                    ],
                ],
            ],
            'required' => ['summary', 'verdict', 'findings', 'previous_findings'],
        ];
    }
}
