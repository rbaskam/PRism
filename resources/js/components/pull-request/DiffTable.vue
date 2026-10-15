<script setup lang="ts">
import { useResizeObserver } from '@vueuse/core';
import { computed, ref } from 'vue';
import { isCodeLine, lineNumber, lineSide, type DiffLine } from './diff';

/**
 * A file's diff as rows with old and new line numbers. Click a line number to select it (shift-click to extend),
 * or drag across the code. Content can be shown under any row through the "after" slot (questions, the ask box).
 */
const props = withDefaults(
    defineProps<{
        path: string;
        lines: DiffLine[];
        /** First and last selected row index. */
        selectedRange?: [number, number] | null;
        /** Rows that have something rendered under them. */
        afterIndexes?: Set<number>;
        /** False for a read-only diff: line numbers can't be clicked and nothing can be selected. */
        interactive?: boolean;
    }>(),
    {
        selectedRange: null,
        afterIndexes: () => new Set<number>(),
        interactive: true,
    },
);

const emit = defineEmits<{
    'line-click': [index: number, extend: boolean];
    'select-rows': [fromIndex: number, toIndex: number];
}>();

const scroller = ref<HTMLElement | null>(null);
const viewportWidth = ref(0);

useResizeObserver(scroller, (entries) => {
    viewportWidth.value = entries[0]?.contentRect.width ?? 0;
});

/** Content under a row stays in view while the code scrolls sideways. */
const afterStyle = computed(() => (viewportWidth.value > 0 ? { width: `${viewportWidth.value}px` } : { width: '100%' }));

const markers: Record<DiffLine['type'], string> = { add: '+', del: '-', context: ' ', hunk: '', meta: '' };

function isSelected(line: DiffLine): boolean {
    return props.selectedRange !== null && line.index >= props.selectedRange[0] && line.index <= props.selectedRange[1] && isCodeLine(line);
}

function rowClass(line: DiffLine): string {
    return `diff-row diff-row-${line.type}${isSelected(line) ? ' diff-row-selected' : ''}`;
}

function rowOf(node: Node | null): HTMLElement | null {
    const element = node instanceof Element ? node : (node?.parentElement ?? null);
    const row = element?.closest<HTMLElement>('[data-line]') ?? null;

    return row && scroller.value?.contains(row) ? row : null;
}

function previousCodeRow(row: HTMLElement): HTMLElement | null {
    let sibling = row.previousElementSibling;

    while (sibling && !(sibling instanceof HTMLElement && sibling.dataset.line)) {
        sibling = sibling.previousElementSibling;
    }

    return sibling as HTMLElement | null;
}

function handleClick(event: MouseEvent): void {
    const target = event.target as HTMLElement;

    if (!props.interactive || !target.closest('[data-gutter]')) {
        return;
    }

    const row = rowOf(target);

    if (row) {
        emit('line-click', Number(row.dataset.index), event.shiftKey);
    }
}

/** Shift-clicking a line number extends the line selection, not the browser's text selection. */
function handleMousedown(event: MouseEvent): void {
    if (props.interactive && event.shiftKey && (event.target as HTMLElement).closest('[data-gutter]')) {
        event.preventDefault();
    }
}

/** Dragging across code selects the rows the text selection starts and ends in. */
function handleMouseup(event: MouseEvent): void {
    if (!props.interactive || (event.target as HTMLElement).closest('[data-gutter]')) {
        return;
    }

    const selection = window.getSelection();

    if (!selection || selection.isCollapsed || selection.rangeCount === 0) {
        return;
    }

    const range = selection.getRangeAt(0);
    const startRow = rowOf(range.startContainer);
    let endRow = rowOf(range.endContainer);

    if (!startRow || !endRow) {
        return;
    }

    // A selection that ends at the very start of a row (e.g. triple-click) doesn't include that row.
    if (endRow !== startRow && range.endOffset === 0) {
        endRow = previousCodeRow(endRow) ?? endRow;
    }

    emit('select-rows', Number(startRow.dataset.index), Number(endRow.dataset.index));
}
</script>

<template>
    <div
        ref="scroller"
        class="diff-table overflow-x-auto font-mono text-xs leading-5"
        @click="handleClick"
        @mousedown="handleMousedown"
        @mouseup="handleMouseup"
    >
        <div class="w-max min-w-full">
            <template v-for="line in lines" :key="line.index">
                <div v-if="line.type === 'hunk'" :class="rowClass(line)" class="flex">
                    <div class="diff-gutter sticky left-0 z-[1] w-24 shrink-0 select-none border-r" />
                    <div class="whitespace-pre px-3 text-sky-700 dark:text-sky-300">{{ line.content }}</div>
                </div>
                <div v-else-if="line.type === 'meta'" :class="rowClass(line)" class="flex">
                    <div class="diff-gutter sticky left-0 z-[1] w-24 shrink-0 select-none border-r" />
                    <div class="whitespace-pre px-3 italic text-muted-foreground">{{ line.content }}</div>
                </div>
                <div
                    v-else
                    :class="rowClass(line)"
                    class="flex"
                    :data-path="path"
                    :data-side="lineSide(line)"
                    :data-line="lineNumber(line)"
                    :data-index="line.index"
                >
                    <div
                        data-gutter
                        class="diff-gutter sticky left-0 z-[1] flex w-24 shrink-0 select-none border-r text-right text-muted-foreground/80"
                        :class="{ 'cursor-pointer hover:text-foreground': interactive }"
                        :title="interactive ? `Select line ${lineNumber(line)} (shift-click to select a range)` : undefined"
                    >
                        <span class="w-12 pr-2">{{ line.oldLine ?? '' }}</span>
                        <span class="w-12 pr-2">{{ line.newLine ?? '' }}</span>
                    </div>
                    <span class="diff-marker w-5 shrink-0 select-none text-center">{{ markers[line.type] }}</span>
                    <span class="whitespace-pre pr-6">{{ line.content || ' ' }}</span>
                </div>
                <div v-if="afterIndexes.has(line.index)" class="sticky left-0 font-sans text-sm leading-normal" :style="afterStyle">
                    <slot name="after" :index="line.index" />
                </div>
            </template>
        </div>
    </div>
</template>

<style scoped>
.diff-row {
    --row-tint: transparent;
    background: var(--row-tint);
}

.diff-row-add {
    --row-tint: rgb(16 185 129 / 0.12);
}

.diff-row-del {
    --row-tint: rgb(239 68 68 / 0.12);
}

.diff-row-hunk {
    --row-tint: rgb(14 165 233 / 0.08);
}

.diff-row-selected {
    --row-tint: rgb(245 158 11 / 0.22);
}

/* Opaque, so code scrolling sideways passes underneath the sticky line numbers. */
.diff-gutter {
    background: linear-gradient(var(--row-tint), var(--row-tint)), hsl(var(--card));
}

.diff-row-selected .diff-gutter {
    box-shadow: inset 3px 0 0 rgb(245 158 11);
}

.diff-row-add .diff-marker {
    color: rgb(5 150 105);
}

.diff-row-del .diff-marker {
    color: rgb(220 38 38);
}
</style>

<style>
html.dark .diff-table .diff-row-add {
    --row-tint: rgb(16 185 129 / 0.16);
}

html.dark .diff-table .diff-row-del {
    --row-tint: rgb(239 68 68 / 0.16);
}

html.dark .diff-table .diff-row-hunk {
    --row-tint: rgb(14 165 233 / 0.12);
}

html.dark .diff-table .diff-row-selected {
    --row-tint: rgb(245 158 11 / 0.24);
}

html.dark .diff-table .diff-row-add .diff-marker {
    color: rgb(52 211 153);
}

html.dark .diff-table .diff-row-del .diff-marker {
    color: rgb(248 113 113);
}
</style>
