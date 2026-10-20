<script setup lang="ts">
import type { PullRequestStatKey, PullRequestStats } from '@/composables/usePullRequestFilters';
import { attentionStyles } from '@/lib/pull-requests';
import { cn } from '@/lib/utils';
import { computed } from 'vue';

const props = defineProps<{
    stats: PullRequestStats;
    /** Cards whose filter is currently applied. */
    active: PullRequestStatKey[];
}>();

const emit = defineEmits<{
    select: [key: PullRequestStatKey];
}>();

interface StatCard {
    key: PullRequestStatKey;
    label: string;
    count: number;
    hint: string | null;
    dot: string;
    countClass: string;
    activeClass: string;
}

const cards = computed<StatCard[]>(() => [
    {
        key: 'awaiting',
        label: 'Awaiting your review',
        count: props.stats.awaiting,
        hint: null,
        dot: attentionStyles.awaiting.dot,
        countClass: 'text-red-600 dark:text-red-400',
        activeClass: 'border-red-500/60 bg-red-500/[0.06] ring-1 ring-red-500/40',
    },
    {
        key: 'approved',
        label: 'Approved by you',
        count: props.stats.approved,
        hint: props.stats.approved_with_new_commits > 0 ? `${props.stats.approved_with_new_commits} with new commits` : null,
        dot: attentionStyles.approved.dot,
        countClass: 'text-emerald-600 dark:text-emerald-400',
        activeClass: 'border-emerald-500/60 bg-emerald-500/[0.06] ring-1 ring-emerald-500/40',
    },
    {
        key: 'changes_requested',
        label: 'Changes requested',
        count: props.stats.changes_requested,
        hint: null,
        dot: attentionStyles.changes_requested.dot,
        countClass: 'text-amber-600 dark:text-amber-400',
        activeClass: 'border-amber-500/60 bg-amber-500/[0.06] ring-1 ring-amber-500/40',
    },
    {
        key: 'authored',
        label: 'Your pull requests',
        count: props.stats.authored,
        hint: null,
        dot: attentionStyles.authored.dot,
        countClass: 'text-violet-600 dark:text-violet-400',
        activeClass: 'border-violet-500/60 bg-violet-500/[0.06] ring-1 ring-violet-500/40',
    },
    {
        key: 'via_teams',
        label: 'Via teams',
        count: props.stats.via_teams,
        hint: null,
        dot: 'bg-indigo-500',
        countClass: 'text-indigo-600 dark:text-indigo-400',
        activeClass: 'border-indigo-500/60 bg-indigo-500/[0.06] ring-1 ring-indigo-500/40',
    },
]);
</script>

<template>
    <div class="grid grid-cols-[repeat(auto-fit,minmax(7.5rem,1fr))] gap-2">
        <button
            v-for="card in cards"
            :key="card.key"
            type="button"
            :aria-pressed="active.includes(card.key)"
            :title="active.includes(card.key) ? 'Click to remove this filter' : `Show only: ${card.label.toLowerCase()}`"
            :class="
                cn(
                    'flex flex-col items-start gap-1.5 rounded-lg border bg-card px-3 py-2.5 text-left shadow-sm transition-colors hover:bg-accent/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                    active.includes(card.key) && card.activeClass,
                )
            "
            @click="emit('select', card.key)"
        >
            <span class="flex items-center gap-1.5 text-xs font-medium leading-tight text-muted-foreground">
                <span class="size-2 shrink-0 rounded-full" :class="card.dot" aria-hidden="true" />
                {{ card.label }}
            </span>
            <span class="text-2xl font-semibold tabular-nums leading-none" :class="card.count > 0 ? card.countClass : 'text-muted-foreground/50'">
                {{ card.count }}
            </span>
            <span v-if="card.hint" class="text-[11px] leading-tight text-amber-700 dark:text-amber-400">{{ card.hint }}</span>
        </button>
    </div>
</template>
