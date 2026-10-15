<script setup lang="ts">
import { computed, ref } from 'vue';

/**
 * The diff context a review thread was left on. Long hunks show their last lines (where the comment is)
 * until expanded.
 */
const props = withDefaults(
    defineProps<{
        hunk: string;
        visibleLines?: number;
    }>(),
    {
        visibleLines: 8,
    },
);

const expanded = ref(false);

const lines = computed(() => props.hunk.replace(/\n$/, '').split('\n'));

const hiddenCount = computed(() => Math.max(0, lines.value.length - props.visibleLines));

const shownLines = computed(() => (expanded.value || hiddenCount.value === 0 ? lines.value : lines.value.slice(-props.visibleLines)));

function lineClass(line: string): string {
    if (line.startsWith('@@')) {
        return 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300';
    }

    if (line.startsWith('+')) {
        return 'bg-emerald-50 text-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-200';
    }

    if (line.startsWith('-')) {
        return 'bg-red-50 text-red-900 dark:bg-red-500/10 dark:text-red-200';
    }

    return 'text-foreground/80';
}
</script>

<template>
    <div class="overflow-hidden rounded-md border bg-muted/30">
        <button
            v-if="hiddenCount > 0"
            type="button"
            class="w-full border-b px-3 py-1 text-left text-[11px] text-muted-foreground hover:bg-muted hover:text-foreground"
            @click="expanded = !expanded"
        >
            {{ expanded ? 'Show less' : `Show ${hiddenCount} more line${hiddenCount === 1 ? '' : 's'} of context` }}
        </button>
        <div class="overflow-x-auto">
            <div class="inline-block min-w-full py-1 font-mono text-xs leading-5">
                <div v-for="(line, index) in shownLines" :key="index" :class="['whitespace-pre px-3', lineClass(line)]">{{ line || ' ' }}</div>
            </div>
        </div>
    </div>
</template>
