<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { AlertTriangle, ChevronRight, ChevronsDownUp, ChevronsUpDown, FileWarning } from 'lucide-vue-next';
import { computed, reactive, watch } from 'vue';
import { countPatchLines, parsePatch, splitUnifiedDiff, type DiffLine, type UnifiedDiffFile } from './diff';
import DiffTable from './DiffTable.vue';

/** Files longer than this, or past the first few, start collapsed. */
const AUTO_OPEN_MAX_LINES = 400;
const AUTO_OPEN_MAX_FILES = 15;

/**
 * Read-only view of a multi-file `git diff`, one collapsible card per file. Diffs are only parsed when opened.
 */
const props = defineProps<{
    diff: string;
}>();

const parsed = computed(() => splitUnifiedDiff(props.diff));

const open = reactive<Record<string, boolean>>({});

/** Parsed rows per file, kept so re-renders (e.g. opening another file) don't parse again. */
const parsedLines = new Map<string, DiffLine[]>();

watch(
    () => props.diff,
    () => parsedLines.clear(),
);

function linesFor(file: UnifiedDiffFile): DiffLine[] {
    let lines = parsedLines.get(file.path);

    if (!lines) {
        lines = parsePatch(file.patch ?? '');
        parsedLines.set(file.path, lines);
    }

    return lines;
}

const statusStyles: Record<UnifiedDiffFile['status'], { label: string; badge: 'success' | 'danger' | 'info' | 'muted' }> = {
    added: { label: 'Added', badge: 'success' },
    removed: { label: 'Deleted', badge: 'danger' },
    renamed: { label: 'Renamed', badge: 'info' },
    modified: { label: 'Modified', badge: 'muted' },
};

function isOpen(file: UnifiedDiffFile, index: number): boolean {
    return open[file.path] ?? (index < AUTO_OPEN_MAX_FILES && countPatchLines(file.patch) <= AUTO_OPEN_MAX_LINES);
}

function toggle(file: UnifiedDiffFile, index: number): void {
    open[file.path] = !isOpen(file, index);
}

function setAll(value: boolean): void {
    for (const file of parsed.value.files) {
        open[file.path] = value;
    }
}
</script>

<template>
    <div class="space-y-2">
        <div class="flex items-center justify-between gap-2">
            <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                Diff · {{ parsed.files.length }} file{{ parsed.files.length === 1 ? '' : 's' }}
            </p>
            <div class="flex items-center gap-1 text-xs">
                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                    @click="setAll(true)"
                >
                    <ChevronsUpDown class="size-3.5" />
                    Expand all
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                    @click="setAll(false)"
                >
                    <ChevronsDownUp class="size-3.5" />
                    Collapse all
                </button>
            </div>
        </div>

        <section v-for="(file, index) in parsed.files" :key="file.path" class="overflow-clip rounded-lg border bg-card text-card-foreground">
            <button
                type="button"
                class="sticky top-0 z-10 flex w-full items-center gap-2 bg-card/95 px-3 py-2 text-left backdrop-blur supports-[backdrop-filter]:bg-card/80"
                :class="{ 'border-b': isOpen(file, index) }"
                :aria-expanded="isOpen(file, index)"
                @click="toggle(file, index)"
            >
                <ChevronRight class="size-4 shrink-0 text-muted-foreground transition-transform" :class="{ 'rotate-90': isOpen(file, index) }" />
                <span class="min-w-0 flex-1 truncate font-mono text-xs" :title="file.path">
                    <template v-if="file.previousPath">
                        <span class="text-muted-foreground line-through decoration-muted-foreground/40">{{ file.previousPath }}</span>
                        <span class="px-1 text-muted-foreground">→</span>
                    </template>
                    <span class="font-semibold">{{ file.path }}</span>
                </span>
                <Badge :variant="statusStyles[file.status].badge" class="px-1.5 text-[10px]">{{ statusStyles[file.status].label }}</Badge>
                <span class="shrink-0 font-mono text-xs tabular-nums">
                    <span class="text-emerald-600 dark:text-emerald-400">+{{ file.additions }}</span>
                    <span class="ml-1 text-red-600 dark:text-red-400">−{{ file.deletions }}</span>
                </span>
            </button>

            <template v-if="isOpen(file, index)">
                <DiffTable v-if="file.patch" :path="file.path" :lines="linesFor(file)" :interactive="false" />
                <p v-else class="flex items-center justify-center gap-2 px-4 py-5 text-sm text-muted-foreground">
                    <FileWarning class="size-4" />
                    Binary file, or no line changes to show.
                </p>
            </template>
        </section>

        <p
            v-if="parsed.truncated"
            class="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
        >
            <AlertTriangle class="mt-px size-3.5 shrink-0" />
            This diff is too large to show in full. Everything above is part of it, and the complete change is what gets pushed.
        </p>
        <p v-if="parsed.files.length === 0 && !parsed.truncated" class="text-sm text-muted-foreground">No diff to show.</p>
    </div>
</template>
