<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDuration, timeAgo } from '@/lib/pull-requests';
import type { PullRequest, ReviewRun } from '@/types';
import { AlertTriangle, Bot, Clock, GitCommitHorizontal, Loader2, RotateCcw, XCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import ElapsedTime from './ElapsedTime.vue';
import MarkdownText from './MarkdownText.vue';
import { formatCost, formatDateTime, runStartedAt, shortSha, startReviewRun, verdictStyles } from './utils';

/**
 * Where the AI review stands: queued, running (with a live timer), failed, or the latest result.
 */
const props = defineProps<{
    pullRequest: PullRequest;
    reviewRuns: ReviewRun[];
    canRunReview: boolean;
}>();

const latestRun = computed<ReviewRun | null>(() => props.reviewRuns[0] ?? null);
const latestCompletedRun = computed<ReviewRun | null>(() => props.reviewRuns.find((run) => run.status === 'completed') ?? null);

const headMovedSinceReview = computed(
    () =>
        latestCompletedRun.value?.head_sha != null &&
        props.pullRequest.head_sha != null &&
        latestCompletedRun.value.head_sha !== props.pullRequest.head_sha,
);

const retrying = ref(false);

function retry(): void {
    startReviewRun(props.pullRequest.id, {
        onStart: () => (retrying.value = true),
        onFinish: () => (retrying.value = false),
    });
}
</script>

<template>
    <div v-if="latestRun" class="flex flex-col gap-3">
        <div
            v-if="latestRun.status === 'queued'"
            class="flex items-center gap-3 rounded-lg border border-dashed px-4 py-3 text-sm"
            role="status"
            aria-live="polite"
        >
            <Clock class="size-4 shrink-0 animate-pulse text-muted-foreground" />
            <span class="font-medium">Queued — waiting for the review worker</span>
            <span class="ml-auto text-xs text-muted-foreground"><ElapsedTime :since="runStartedAt(latestRun)" /></span>
        </div>

        <div
            v-else-if="latestRun.status === 'running'"
            class="flex items-center gap-3 rounded-lg border border-violet-300 bg-violet-50 px-4 py-3 text-sm text-violet-950 dark:border-violet-500/30 dark:bg-violet-500/10 dark:text-violet-100"
            role="status"
            aria-live="polite"
        >
            <Loader2 class="size-4 shrink-0 animate-spin text-violet-500" />
            <span class="font-medium">Claude is reviewing… (<ElapsedTime :since="runStartedAt(latestRun)" />)</span>
            <span class="ml-auto hidden text-xs opacity-75 sm:inline">Findings appear here when it finishes. You can keep working.</span>
        </div>

        <div
            v-else-if="latestRun.status === 'failed'"
            class="flex items-start gap-3 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"
            role="alert"
        >
            <XCircle class="mt-0.5 size-4 shrink-0" />
            <div class="min-w-0 flex-1 space-y-1">
                <p class="font-medium">
                    The AI review failed
                    <span class="font-normal opacity-75" :title="formatDateTime(latestRun.finished_at ?? latestRun.created_at)">
                        {{ timeAgo(latestRun.finished_at ?? latestRun.created_at) }}
                    </span>
                </p>
                <p v-if="latestRun.error" class="max-h-40 overflow-auto whitespace-pre-wrap break-words font-mono text-xs">{{ latestRun.error }}</p>
            </div>
            <Button
                v-if="canRunReview"
                size="sm"
                variant="outline"
                class="shrink-0 border-red-300 bg-transparent hover:bg-red-100 dark:border-red-500/40 dark:hover:bg-red-500/20"
                :disabled="retrying"
                @click="retry"
            >
                <Loader2 v-if="retrying" class="animate-spin" />
                <RotateCcw v-else />
                Try again
            </Button>
        </div>

        <section v-if="latestCompletedRun" class="rounded-lg border bg-card text-card-foreground">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-2.5">
                <div class="flex flex-wrap items-center gap-2">
                    <Bot class="size-4 text-violet-500" />
                    <h3 class="text-sm font-medium">{{ latestCompletedRun === latestRun ? 'Latest AI review' : 'Last completed AI review' }}</h3>
                    <Badge v-if="latestCompletedRun.verdict" :variant="verdictStyles[latestCompletedRun.verdict].badge">
                        {{ verdictStyles[latestCompletedRun.verdict].label }}
                    </Badge>
                </div>
                <div class="flex items-center gap-2 text-xs text-muted-foreground">
                    <span v-if="latestCompletedRun.head_sha" class="inline-flex items-center gap-1 font-mono" title="Commit that was reviewed">
                        <GitCommitHorizontal class="size-3.5" />
                        {{ shortSha(latestCompletedRun.head_sha) }}
                    </span>
                    <span v-if="latestCompletedRun.finished_at" :title="formatDateTime(latestCompletedRun.finished_at)">
                        {{ timeAgo(latestCompletedRun.finished_at) }}
                    </span>
                </div>
            </div>

            <MarkdownText v-if="latestCompletedRun.summary" :text="latestCompletedRun.summary" class="px-4 py-3" />

            <div class="flex flex-wrap items-center gap-x-5 gap-y-1 border-t px-4 py-2 text-xs text-muted-foreground">
                <span>
                    <span class="font-semibold tabular-nums text-foreground">{{ latestCompletedRun.new_findings_count }}</span> new
                </span>
                <span>
                    <span class="font-semibold tabular-nums text-foreground">{{ latestCompletedRun.repeated_findings_count }}</span> still present
                </span>
                <span>
                    <span class="font-semibold tabular-nums text-foreground">{{ latestCompletedRun.resolved_findings_count }}</span> resolved
                </span>
                <span v-if="latestCompletedRun.cost_usd != null" class="tabular-nums">{{ formatCost(latestCompletedRun.cost_usd) }}</span>
                <span v-if="latestCompletedRun.duration_ms != null" class="tabular-nums">{{ formatDuration(latestCompletedRun.duration_ms) }}</span>
                <span v-if="latestCompletedRun.model" class="font-mono">{{ latestCompletedRun.model }}</span>
            </div>

            <p
                v-if="headMovedSinceReview"
                class="flex items-center gap-2 border-t bg-amber-50 px-4 py-2 text-xs text-amber-900 dark:bg-amber-500/10 dark:text-amber-200"
            >
                <AlertTriangle class="size-3.5 shrink-0" />
                New commits were pushed after this review. Re-run it to check them.
            </p>
        </section>
    </div>
</template>
