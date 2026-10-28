<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { formatDuration, timeAgo } from '@/lib/pull-requests';
import type { ReviewRun } from '@/types';
import { ChevronRight, History, Loader2 } from 'lucide-vue-next';
import ElapsedTime from './ElapsedTime.vue';
import MarkdownText from './MarkdownText.vue';
import { formatCost, formatDateTime, runStartedAt, runStatusStyles, shortSha, verdictStyles } from './utils';

/** Every AI review of the pull request, newest first, with what it cost and the prompt it ran with. */
defineProps<{
    reviewRuns: ReviewRun[];
}>();
</script>

<template>
    <div>
        <div v-if="reviewRuns.length === 0" class="rounded-lg border border-dashed px-6 py-10 text-center">
            <History class="mx-auto size-8 text-muted-foreground/60" />
            <p class="mt-2 text-sm font-medium">No AI reviews yet</p>
            <p class="text-sm text-muted-foreground">Each AI review of this pull request is listed here with its cost and prompt.</p>
        </div>

        <ol v-else class="divide-y rounded-lg border bg-card text-card-foreground">
            <li v-for="run in reviewRuns" :key="run.id" class="space-y-2 px-4 py-3">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-medium" :title="formatDateTime(run.created_at)">{{ formatDateTime(run.created_at) }}</span>
                    <Badge :variant="runStatusStyles[run.status].badge">
                        <Loader2 v-if="run.is_active" class="animate-spin" />
                        {{ runStatusStyles[run.status].label }}
                        <ElapsedTime v-if="run.is_active" :since="runStartedAt(run)" />
                    </Badge>
                    <Badge v-if="run.verdict" :variant="verdictStyles[run.verdict].badge">{{ verdictStyles[run.verdict].label }}</Badge>
                    <span class="ml-auto text-xs text-muted-foreground">{{ timeAgo(run.created_at) }}</span>
                </div>

                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                    <template v-if="run.status === 'completed'">
                        <span
                            ><span class="font-semibold tabular-nums text-foreground">{{ run.new_findings_count }}</span> new</span
                        >
                        <span
                            ><span class="font-semibold tabular-nums text-foreground">{{ run.repeated_findings_count }}</span> still present</span
                        >
                        <span
                            ><span class="font-semibold tabular-nums text-foreground">{{ run.resolved_findings_count }}</span> resolved</span
                        >
                    </template>
                    <span v-if="run.cost_usd != null" class="tabular-nums">{{ formatCost(run.cost_usd) }}</span>
                    <span v-if="run.duration_ms != null" class="tabular-nums">{{ formatDuration(run.duration_ms) }}</span>
                    <span v-if="run.model" class="font-mono">{{ run.model }}</span>
                    <span v-if="run.head_sha" class="font-mono" title="Commit that was reviewed">{{ shortSha(run.head_sha) }}</span>
                </div>

                <MarkdownText v-if="run.summary" :text="run.summary" />

                <p
                    v-if="run.error"
                    class="max-h-40 overflow-auto whitespace-pre-wrap break-words rounded-md border border-red-200 bg-red-50 px-3 py-2 font-mono text-xs text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"
                >
                    {{ run.error }}
                </p>

                <Collapsible v-if="run.prompt" v-slot="{ open }">
                    <CollapsibleTrigger class="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground">
                        <ChevronRight class="size-3.5 transition-transform" :class="{ 'rotate-90': open }" />
                        Prompt used
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <pre
                            class="mt-2 max-h-80 overflow-auto whitespace-pre-wrap rounded-md border bg-muted/50 p-3 font-mono text-xs leading-relaxed"
                            >{{ run.prompt }}</pre>
                    </CollapsibleContent>
                </Collapsible>
            </li>
        </ol>
    </div>
</template>
