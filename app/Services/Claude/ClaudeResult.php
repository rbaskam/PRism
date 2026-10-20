<?php

namespace App\Services\Claude;

/**
 * The parsed output of a headless `claude -p --output-format json` run.
 */
final readonly class ClaudeResult
{
    /**
     * @param  array<string, mixed>|null  $structuredOutput
     */
    public function __construct(
        public ?array $structuredOutput,
        public string $text,
        public ?float $costUsd,
        public ?int $durationMs,
        public ?string $sessionId,
        public string $rawOutput,
    ) {}

    /**
     * @throws ClaudeException
     */
    public static function fromJson(string $output): self
    {
        $decoded = json_decode(trim($output), true);

        if (! is_array($decoded)) {
            // Anything printed before the result (such as a warning) breaks the JSON, so fall back to the last line.
            $lines = preg_split('/\R/', trim($output)) ?: [];
            $decoded = json_decode((string) end($lines), true);
        }

        if (! is_array($decoded) || ($decoded['type'] ?? null) !== 'result') {
            throw new ClaudeException('Claude Code returned output that could not be read: '.str($output)->limit(500));
        }

        if (($decoded['is_error'] ?? false) || ($decoded['subtype'] ?? 'success') !== 'success') {
            throw new ClaudeException('Claude Code stopped with an error: '.($decoded['result'] ?? $decoded['subtype'] ?? 'unknown error'));
        }

        return new self(
            structuredOutput: is_array($decoded['structured_output'] ?? null) ? $decoded['structured_output'] : null,
            text: (string) ($decoded['result'] ?? ''),
            costUsd: isset($decoded['total_cost_usd']) ? (float) $decoded['total_cost_usd'] : null,
            durationMs: isset($decoded['duration_ms']) ? (int) $decoded['duration_ms'] : null,
            sessionId: $decoded['session_id'] ?? null,
            rawOutput: $output,
        );
    }
}
