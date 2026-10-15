<script setup lang="ts">
import ExternalLink from '@/components/ExternalLink.vue';
import { Badge } from '@/components/ui/badge';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { timeAgo } from '@/lib/pull-requests';
import type { CodeChange, PullRequest, PullRequestPermissions } from '@/types';
import { ChevronRight, ExternalLink as ExternalLinkIcon, Wand2 } from 'lucide-vue-next';
import { computed } from 'vue';
import CodeChangeCard from './CodeChangeCard.vue';
import FixWithClaudeButton from './FixWithClaudeButton.vue';
import { codeChangeStatusStyles, formatDateTime } from './utils';

/**
 * "Fix with Claude" results: the latest change in full, earlier ones compactly below.
 */
const props = defineProps<{
    codeChanges: CodeChange[];
    pullRequest: PullRequest;
    permissions: PullRequestPermissions;
}>();

const emit = defineEmits<{
    fix: [];
    retry: [change: CodeChange];
}>();

const latest = computed(() => props.codeChanges[0] ?? null);
const earlier = computed(() => props.codeChanges.slice(1));
const isWorking = computed(() => props.codeChanges.some((change) => change.is_active));

function describe(change: CodeChange): string {
    const text = change.instructions ?? change.summary ?? '';
    const firstLine = text.split('\n').find((line) => line.trim() !== '') ?? '';

    if (firstLine) {
        return firstLine.replace(/^[#>*\-\s]+/, '').slice(0, 140);
    }

    return change.finding_ids.length ? `Fix ${change.finding_ids.length} finding${change.finding_ids.length === 1 ? '' : 's'}` : 'Changes';
}
</script>

<template>
    <div>
        <div v-if="!latest" class="flex flex-col items-center gap-3 rounded-lg border border-dashed px-6 py-12 text-center">
            <Wand2 class="size-8 text-violet-500/70" />
            <div class="space-y-1">
                <p class="text-sm font-medium">Let Claude make the fixes</p>
                <p class="max-w-md text-sm text-muted-foreground">
                    Claude changes the code in a separate worktree of your local checkout and commits locally. You review the diff, then push it to
                    this branch or open a new pull request, or discard it.
                </p>
            </div>
            <FixWithClaudeButton :permissions="permissions" :is-working="isWorking" size="default" variant="default" @click="emit('fix')" />
        </div>

        <div v-else class="space-y-6">
            <div class="flex items-center justify-between gap-2">
                <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Latest</p>
                <FixWithClaudeButton :permissions="permissions" :is-working="isWorking" @click="emit('fix')" />
            </div>

            <CodeChangeCard :change="latest" :head-ref="pullRequest.head_ref" :permissions="permissions" @retry="emit('retry', $event)" />

            <section v-if="earlier.length" class="space-y-2">
                <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Earlier</p>
                <div class="divide-y rounded-lg border bg-card">
                    <Collapsible v-for="change in earlier" :key="change.id" v-slot="{ open }">
                        <div class="flex items-center gap-2 px-3 py-2 text-sm">
                            <CollapsibleTrigger class="flex min-w-0 flex-1 items-center gap-2 text-left">
                                <ChevronRight class="size-4 shrink-0 text-muted-foreground transition-transform" :class="{ 'rotate-90': open }" />
                                <Badge :variant="codeChangeStatusStyles[change.status].badge" class="shrink-0 px-1.5 text-[10px]">
                                    {{ codeChangeStatusStyles[change.status].label }}
                                </Badge>
                                <span class="min-w-0 truncate">{{ describe(change) }}</span>
                            </CollapsibleTrigger>
                            <span v-if="change.additions || change.deletions" class="hidden shrink-0 font-mono text-xs tabular-nums sm:inline">
                                <span class="text-emerald-600 dark:text-emerald-400">+{{ change.additions }}</span>
                                <span class="ml-1 text-red-600 dark:text-red-400">−{{ change.deletions }}</span>
                            </span>
                            <ExternalLink
                                v-if="change.created_pull_request_url"
                                :href="change.created_pull_request_url"
                                class="inline-flex shrink-0 items-center gap-1 text-xs font-medium hover:underline"
                            >
                                <ExternalLinkIcon class="size-3" />
                                Pull request
                            </ExternalLink>
                            <span class="shrink-0 text-xs text-muted-foreground" :title="formatDateTime(change.created_at)">{{
                                timeAgo(change.created_at)
                            }}</span>
                        </div>
                        <CollapsibleContent>
                            <div class="px-3 pb-3">
                                <CodeChangeCard
                                    :change="change"
                                    :head-ref="pullRequest.head_ref"
                                    :permissions="permissions"
                                    @retry="emit('retry', $event)"
                                />
                            </div>
                        </CollapsibleContent>
                    </Collapsible>
                </div>
            </section>
        </div>
    </div>
</template>
