<script setup lang="ts">
import { useResizeObserver } from '@vueuse/core';
import { ChevronsDown, ChevronsUpDown, Loader2 } from 'lucide-vue-next';
import { computed, nextTick, onMounted, ref, shallowRef, watch } from 'vue';
import { isCodeLine, lineNumber, lineSide, splitRows, wordDiffs, type DiffGap, type DiffLine, type SplitRow } from './diff';
import { highlightDiff, renderPieces, type HighlightToken, type RenderPiece } from './highlight';

/**
 * A file's diff, either as one column with old and new line numbers or side by side. Click a line number to select it
 * (shift-click to extend), drag across the code, or focus the diff and use ↑/↓ (shift to extend). Content can be
 * shown under any row through the "after" slot (questions, threads, findings, the comment box).
 *
 * Code is syntax highlighted once the file's language has loaded, and the words that changed within edited lines are
 * marked. Unchanged lines hidden between hunks can be revealed when the parent passes `hiddenAbove`.
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
        layout?: 'unified' | 'split';
        /** Unchanged lines hidden above each hunk, by the hunk header's row index, that can be revealed. */
        hiddenAbove?: Map<number, number>;
        /** Whether the rest of the file after the last hunk can be revealed. */
        canExpandEnd?: boolean;
        /** The file's full text is loading to reveal hidden lines. */
        expanding?: boolean;
    }>(),
    {
        selectedRange: null,
        afterIndexes: () => new Set<number>(),
        interactive: true,
        layout: 'unified',
        hiddenAbove: () => new Map<number, number>(),
        canExpandEnd: false,
        expanding: false,
    },
);

const emit = defineEmits<{
    'line-click': [index: number, extend: boolean];
    'select-rows': [fromIndex: number, toIndex: number];
    expand: [gap: DiffGap];
}>();

const scroller = ref<HTMLElement | null>(null);
const viewportWidth = ref(0);

useResizeObserver(scroller, (entries) => {
    viewportWidth.value = entries[0]?.contentRect.width ?? 0;
});

/*
 * Measure before the first paint too. Until then content under a row would be as wide as its longest line of text
 * (the rows are max-content wide), and shrinking it once the observer reports would resize the table again.
 */
onMounted(() => {
    viewportWidth.value = scroller.value?.clientWidth ?? 0;
});

/** Content under a row stays in view while the code scrolls sideways. */
const afterStyle = computed(() => (viewportWidth.value > 0 ? { width: `${viewportWidth.value}px` } : { width: '100%' }));

/** The words that changed within edited lines, highlighted like GitHub does. */
const words = computed(() => wordDiffs(props.lines));

/** Syntax colours by row index, once the language has loaded. Rows show plain text until then. */
const highlighted = shallowRef<Map<number, HighlightToken[]> | null>(null);
let highlightRun = 0;

watch(
    () => [props.path, props.lines] as const,
    async ([path, lines]) => {
        const run = ++highlightRun;
        const result = await highlightDiff(path, lines);

        if (run === highlightRun) {
            highlighted.value = result;
        }
    },
    { immediate: true },
);

const pieces = computed(() => {
    const result = new Map<number, RenderPiece[]>();

    for (const line of props.lines) {
        if (isCodeLine(line)) {
            result.set(line.index, renderPieces(line.content, highlighted.value?.get(line.index), words.value.get(line.index)));
        }
    }

    return result;
});

const rows = computed<SplitRow[]>(() => (props.layout === 'split' ? splitRows(props.lines) : []));

const markers: Record<DiffLine['type'], string> = { add: '+', del: '-', context: ' ', hunk: '', meta: '' };

function isSelected(line: DiffLine): boolean {
    return props.selectedRange !== null && line.index >= props.selectedRange[0] && line.index <= props.selectedRange[1] && isCodeLine(line);
}

/** Expanded lines aren't part of the diff: GitHub can't take comments on them, so they can't be selected. */
function isSelectable(line: DiffLine): boolean {
    return props.interactive && isCodeLine(line) && !line.expanded;
}

function rowClass(line: DiffLine): string {
    return `diff-row diff-row-${line.expanded ? 'expanded' : line.type}${isSelected(line) ? ' diff-row-selected' : ''}`;
}

/** The rows under which a side-by-side row has content: its left line and, if different, its right line. */
function afterIndexesOf(row: SplitRow): number[] {
    return [...new Set([row.left?.index, row.right?.index])].filter((index): index is number => index !== undefined && props.afterIndexes.has(index));
}

function hiddenLabel(count: number): string {
    return `Show ${count} hidden line${count === 1 ? '' : 's'}`;
}

/*
 * Selecting lines with the mouse.
 */
function rowOf(node: Node | null): HTMLElement | null {
    const element = node instanceof Element ? node : (node?.parentElement ?? null);
    const row = element?.closest<HTMLElement>('[data-index]') ?? null;

    return row && scroller.value?.contains(row) ? row : null;
}

function previousCodeRow(row: HTMLElement): HTMLElement | null {
    let sibling = row.previousElementSibling;

    while (sibling && !(sibling instanceof HTMLElement && sibling.dataset.index)) {
        sibling = sibling.previousElementSibling;
    }

    return sibling as HTMLElement | null;
}

/** The row the keyboard selection moves from: the last one clicked or reached with the arrow keys. */
const cursor = ref<number | null>(null);

watch(
    () => props.selectedRange,
    (range) => {
        if (range === null) {
            cursor.value = null;
        }
    },
);

function handleClick(event: MouseEvent): void {
    const target = event.target as HTMLElement;

    if (!props.interactive || !target.closest('[data-gutter]')) {
        return;
    }

    const row = rowOf(target);

    if (row) {
        cursor.value = Number(row.dataset.index);
        emit('line-click', cursor.value, event.shiftKey);
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

    cursor.value = Number(endRow.dataset.index);
    emit('select-rows', Number(startRow.dataset.index), cursor.value);
}

/*
 * Selecting lines with the keyboard: focus the diff, then ↑/↓ moves the selection and shift extends it.
 */
const selectableIndexes = computed(() => props.lines.filter(isSelectable).map((line) => line.index));

function handleKeydown(event: KeyboardEvent): void {
    if (
        !props.interactive ||
        event.target !== scroller.value ||
        event.metaKey ||
        event.ctrlKey ||
        event.altKey ||
        (event.key !== 'ArrowDown' && event.key !== 'ArrowUp')
    ) {
        return;
    }

    event.preventDefault();

    const indexes = selectableIndexes.value;
    const from = cursor.value ?? props.selectedRange?.[1] ?? null;
    const position = from === null ? -1 : indexes.indexOf(from);
    const step = event.key === 'ArrowDown' ? 1 : -1;
    const next = position === -1 ? (step === 1 ? 0 : indexes.length - 1) : Math.min(indexes.length - 1, Math.max(0, position + step));
    const index = indexes[next];
    const extend = event.shiftKey && props.selectedRange !== null;

    if (index === undefined || (index === from && props.selectedRange !== null)) {
        return;
    }

    cursor.value = index;
    emit('line-click', index, extend);
    nextTick(() => scroller.value?.querySelector(`[data-index="${index}"]`)?.scrollIntoView({ block: 'nearest' }));
}
</script>

<template>
    <div
        ref="scroller"
        class="diff-table font-mono text-xs leading-5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
        :class="layout === 'split' ? 'overflow-x-hidden' : 'overflow-x-auto'"
        :tabindex="interactive ? 0 : undefined"
        :role="interactive ? 'group' : undefined"
        :aria-label="interactive ? `Diff of ${path}. Use the up and down arrow keys to select lines, shift to extend.` : undefined"
        @click="handleClick"
        @mousedown="handleMousedown"
        @mouseup="handleMouseup"
        @keydown="handleKeydown"
    >
        <!-- One column, GitHub's unified view. -->
        <div v-if="layout === 'unified'" class="w-max min-w-full">
            <template v-for="line in lines" :key="line.index">
                <div v-if="line.type === 'hunk'" :class="rowClass(line)" class="flex">
                    <div class="diff-gutter sticky left-0 z-[1] flex w-24 shrink-0 select-none items-center justify-center border-r">
                        <button
                            v-if="hiddenAbove.has(line.index)"
                            type="button"
                            class="diff-expand"
                            :disabled="expanding"
                            :title="hiddenLabel(hiddenAbove.get(line.index)!)"
                            @click.stop="emit('expand', line.index)"
                        >
                            <Loader2 v-if="expanding" class="size-3.5 animate-spin" />
                            <ChevronsUpDown v-else class="size-3.5" />
                            <span class="sr-only">{{ hiddenLabel(hiddenAbove.get(line.index)!) }}</span>
                        </button>
                    </div>
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
                    :data-path="line.expanded ? undefined : path"
                    :data-side="line.expanded ? undefined : lineSide(line)"
                    :data-line="line.expanded ? undefined : lineNumber(line)"
                    :data-index="line.expanded ? undefined : line.index"
                >
                    <div
                        data-gutter
                        class="diff-gutter sticky left-0 z-[1] flex w-24 shrink-0 select-none border-r text-right text-muted-foreground/80"
                        :class="{ 'cursor-pointer hover:text-foreground': isSelectable(line) }"
                        :title="isSelectable(line) ? `Select line ${lineNumber(line)} (shift-click to select a range)` : undefined"
                    >
                        <span class="w-12 pr-2">{{ line.oldLine ?? '' }}</span>
                        <span class="w-12 pr-2">{{ line.newLine ?? '' }}</span>
                    </div>
                    <span class="diff-marker w-5 shrink-0 select-none text-center">{{ markers[line.type] }}</span>
                    <span class="whitespace-pre pr-6"
                        ><template v-for="(piece, position) in pieces.get(line.index)" :key="position"
                            ><mark v-if="piece.changed" class="diff-word" :class="{ 'diff-token': piece.style }" :style="piece.style ?? undefined">{{
                                piece.text
                            }}</mark
                            ><span v-else-if="piece.style" class="diff-token" :style="piece.style">{{ piece.text }}</span
                            ><template v-else>{{ piece.text }}</template></template
                        ><template v-if="!line.content">{{ ' ' }}</template></span
                    >
                </div>
                <div v-if="afterIndexes.has(line.index)" class="sticky left-0 font-sans text-sm leading-normal" :style="afterStyle">
                    <slot name="after" :index="line.index" />
                </div>
            </template>
        </div>

        <!-- Side by side: the old version on the left, the new one on the right. Long lines wrap. -->
        <div v-else>
            <template v-for="row in rows" :key="row.key">
                <div v-if="row.full && row.full.type === 'hunk'" :class="rowClass(row.full)" class="flex">
                    <div class="diff-gutter flex w-12 shrink-0 select-none items-center justify-center border-r">
                        <button
                            v-if="hiddenAbove.has(row.full.index)"
                            type="button"
                            class="diff-expand"
                            :disabled="expanding"
                            :title="hiddenLabel(hiddenAbove.get(row.full.index)!)"
                            @click.stop="emit('expand', row.full.index)"
                        >
                            <Loader2 v-if="expanding" class="size-3.5 animate-spin" />
                            <ChevronsUpDown v-else class="size-3.5" />
                            <span class="sr-only">{{ hiddenLabel(hiddenAbove.get(row.full.index)!) }}</span>
                        </button>
                    </div>
                    <div class="min-w-0 truncate px-3 text-sky-700 dark:text-sky-300">{{ row.full.content }}</div>
                </div>
                <div v-else-if="row.full" :class="rowClass(row.full)" class="flex">
                    <div class="diff-gutter w-12 shrink-0 select-none border-r" />
                    <div class="px-3 italic text-muted-foreground">{{ row.full.content }}</div>
                </div>
                <div v-else class="grid grid-cols-2">
                    <template v-for="(cell, side) in [row.left, row.right]" :key="side">
                        <div
                            v-if="cell"
                            :class="[rowClass(cell), side === 0 ? 'border-r' : '']"
                            class="flex min-w-0"
                            :data-path="cell.expanded ? undefined : path"
                            :data-side="cell.expanded ? undefined : side === 0 ? 'LEFT' : 'RIGHT'"
                            :data-line="
                                cell.expanded || (side === 0 && cell.type === 'context') ? undefined : side === 0 ? cell.oldLine : cell.newLine
                            "
                            :data-index="cell.expanded ? undefined : cell.index"
                        >
                            <div
                                data-gutter
                                class="diff-gutter w-12 shrink-0 select-none border-r pr-2 text-right text-muted-foreground/80"
                                :class="{ 'cursor-pointer hover:text-foreground': isSelectable(cell) }"
                                :title="
                                    isSelectable(cell)
                                        ? `Select line ${side === 0 ? cell.oldLine : cell.newLine} (shift-click to select a range)`
                                        : undefined
                                "
                            >
                                {{ side === 0 ? cell.oldLine : cell.newLine }}
                            </div>
                            <span class="diff-marker w-5 shrink-0 select-none text-center">{{
                                cell.type === 'context' ? ' ' : markers[cell.type]
                            }}</span>
                            <span class="min-w-0 flex-1 whitespace-pre-wrap break-all pr-3"
                                ><template v-for="(piece, position) in pieces.get(cell.index)" :key="position"
                                    ><mark
                                        v-if="piece.changed"
                                        class="diff-word"
                                        :class="{ 'diff-token': piece.style }"
                                        :style="piece.style ?? undefined"
                                        >{{ piece.text }}</mark
                                    ><span v-else-if="piece.style" class="diff-token" :style="piece.style">{{ piece.text }}</span
                                    ><template v-else>{{ piece.text }}</template></template
                                ><template v-if="!cell.content">{{ ' ' }}</template></span
                            >
                        </div>
                        <div v-else class="diff-row diff-row-empty" :class="side === 0 ? 'border-r' : ''" />
                    </template>
                </div>
                <div v-for="index in afterIndexesOf(row)" :key="`after-${index}`" class="font-sans text-sm leading-normal">
                    <slot name="after" :index="index" />
                </div>
            </template>
        </div>

        <div v-if="canExpandEnd" class="diff-row diff-row-hunk flex" :class="{ 'w-max min-w-full': layout === 'unified' }">
            <div
                class="diff-gutter flex shrink-0 select-none items-center justify-center border-r"
                :class="layout === 'unified' ? 'sticky left-0 z-[1] w-24' : 'w-12'"
            >
                <button type="button" class="diff-expand" :disabled="expanding" title="Show the rest of the file" @click.stop="emit('expand', 'end')">
                    <Loader2 v-if="expanding" class="size-3.5 animate-spin" />
                    <ChevronsDown v-else class="size-3.5" />
                    <span class="sr-only">Show the rest of the file</span>
                </button>
            </div>
            <div class="px-3 font-sans text-muted-foreground" />
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

.diff-row-expanded {
    --row-tint: hsl(var(--muted) / 0.4);
}

.diff-row-empty {
    --row-tint: hsl(var(--muted) / 0.6);
}

.diff-token {
    color: var(--shiki-light);
}

.diff-word {
    color: inherit;
    border-radius: 2px;
}

.diff-word.diff-token {
    color: var(--shiki-light);
}

.diff-row-add .diff-word {
    background: rgb(16 185 129 / 0.3);
}

.diff-row-del .diff-word {
    background: rgb(239 68 68 / 0.3);
}

.diff-row-selected {
    --row-tint: hsl(var(--primary) / 0.14);
}

/* Opaque, so code scrolling sideways passes underneath the sticky line numbers. */
.diff-gutter {
    background: linear-gradient(var(--row-tint), var(--row-tint)), hsl(var(--card));
}

.diff-row-selected .diff-gutter {
    box-shadow: inset 3px 0 0 hsl(var(--ring));
}

.diff-row-add .diff-marker {
    color: rgb(5 150 105);
}

.diff-row-del .diff-marker {
    color: rgb(220 38 38);
}

.diff-expand {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 0.25rem;
    padding: 0.125rem 0.375rem;
    color: hsl(var(--muted-foreground));
}

.diff-expand:hover:not(:disabled) {
    background: hsl(var(--primary) / 0.12);
    color: hsl(var(--foreground));
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
    --row-tint: hsl(var(--primary) / 0.2);
}

html.dark .diff-table .diff-token {
    color: var(--shiki-dark);
}

html.dark .diff-table .diff-row-add .diff-word {
    background: rgb(16 185 129 / 0.35);
}

html.dark .diff-table .diff-row-del .diff-word {
    background: rgb(239 68 68 / 0.35);
}

html.dark .diff-table .diff-row-add .diff-marker {
    color: rgb(52 211 153);
}

html.dark .diff-table .diff-row-del .diff-marker {
    color: rgb(248 113 113);
}
</style>
