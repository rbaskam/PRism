<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { sendJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import type { CodeQuestion, PullRequest, PullRequestFile, PullRequestFiles, PullRequestPermissions } from '@/types';
import { router } from '@inertiajs/vue3';
import { useEventListener } from '@vueuse/core';
import { AlertTriangle, Check, ChevronsDownUp, ChevronsUpDown, Info, ListTree, Loader2, RotateCcw, Search, Sparkles, X } from 'lucide-vue-next';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { groupQuestionThreads, threadsByPath, type QuestionThread, type RevealTarget } from './code-questions';
import DiffFile from './DiffFile.vue';

/**
 * The pull request's changed files, GitHub style: mark files as viewed, filter them, and select lines to ask Claude about.
 */
const props = defineProps<{
    files: PullRequestFiles;
    pullRequest: PullRequest;
    codeQuestions: CodeQuestion[];
    permissions: PullRequestPermissions;
    /** Whether the Files changed tab is showing, so keyboard shortcuts only apply there. */
    isActive: boolean;
    revealTarget: RevealTarget | null;
}>();

const NO_THREADS: QuestionThread[] = [];

const viewed = reactive<Record<string, boolean>>({});
const changedSinceViewed = reactive<Record<string, boolean>>({});
const expanded = reactive<Record<string, boolean>>({});
const diffRequested = reactive<Record<string, boolean>>({});
const forceRendered = reactive<Record<string, boolean>>({});

const filter = ref('');
const hideViewed = ref(false);
const showFileList = ref(props.files.files.length <= 15);
const viewedError = ref<string | null>(null);
const syncWarning = ref<string | null>(null);
const retrying = ref(false);

const selection = ref<{ path: string; anchor: number; focus: number } | null>(null);
const composerOpen = ref(false);
const composerPrompt = ref('');

/** Seed local state from the server whenever the file list is (re)loaded. */
watch(
    () => props.files,
    (files) => {
        for (const state of [viewed, changedSinceViewed, expanded]) {
            for (const key of Object.keys(state)) {
                delete state[key];
            }
        }

        for (const file of files.files) {
            viewed[file.path] = file.is_viewed;
            changedSinceViewed[file.path] = file.changed_since_viewed;
            expanded[file.path] = !file.is_viewed;
        }
    },
    { immediate: true },
);

const allFiles = computed(() => props.files.files);
const totalAdditions = computed(() => allFiles.value.reduce((total, file) => total + file.additions, 0));
const totalDeletions = computed(() => allFiles.value.reduce((total, file) => total + file.deletions, 0));
const viewedCount = computed(() => allFiles.value.filter((file) => viewed[file.path]).length);
const viewedPercentage = computed(() => (allFiles.value.length ? Math.round((viewedCount.value / allFiles.value.length) * 100) : 0));

const visibleFiles = computed(() => {
    const term = filter.value.trim().toLowerCase();

    return allFiles.value.filter(
        (file) =>
            (!hideViewed.value || !viewed[file.path]) &&
            (term === '' || file.path.toLowerCase().includes(term) || (file.previous_path?.toLowerCase().includes(term) ?? false)),
    );
});

const threads = computed(() => groupQuestionThreads(props.codeQuestions));
const threadsForPath = computed(() => threadsByPath(threads.value));
const threadsOnRemovedFiles = computed(() => {
    const paths = new Set(allFiles.value.map((file) => file.path));

    return threads.value.filter((thread) => !paths.has(thread.root.path)).length;
});

function showAllFiles(): void {
    filter.value = '';
    hideViewed.value = false;
}

function setAllExpanded(value: boolean): void {
    for (const file of visibleFiles.value) {
        expanded[file.path] = value;
    }
}

function fileCard(path: string): HTMLElement | null {
    return document.querySelector<HTMLElement>(`[data-file-path="${CSS.escape(path)}"]`);
}

function jumpTo(file: PullRequestFile): void {
    expanded[file.path] = true;
    nextTick(() => fileCard(file.path)?.scrollIntoView({ block: 'start', behavior: 'smooth' }));
}

async function setViewed(file: PullRequestFile, value: boolean): Promise<void> {
    const previous = { viewed: viewed[file.path], expanded: expanded[file.path], changed: changedSinceViewed[file.path] };

    viewed[file.path] = value;
    expanded[file.path] = !value;
    viewedError.value = null;

    if (value) {
        changedSinceViewed[file.path] = false;

        if (selection.value?.path === file.path) {
            clearSelection();
        }
    }

    try {
        const result = await sendJson<{ viewed: boolean; synced_to_github: boolean }>(
            'PUT',
            route('pull-requests.file-views.update', { pullRequest: props.pullRequest.id }),
            { path: file.path, viewed: value, file_sha: file.sha },
            'Could not update viewed state',
        );

        syncWarning.value = result.synced_to_github ? null : 'Saved in PRism, but the “Viewed” state on GitHub could not be updated.';
    } catch (error) {
        viewed[file.path] = previous.viewed;
        expanded[file.path] = previous.expanded;
        changedSinceViewed[file.path] = previous.changed;
        viewedError.value = error instanceof Error ? error.message : 'Could not update viewed state.';
    }
}

function retry(): void {
    router.reload({
        only: ['files'],
        onStart: () => (retrying.value = true),
        onFinish: () => (retrying.value = false),
    });
}

/*
 * Line selection: one range at a time, in one file.
 */
function selectLine(path: string, index: number, extend: boolean): void {
    const current = selection.value;

    if (extend && current?.path === path) {
        selection.value = { ...current, focus: index };
    } else if (current?.path === path && current.anchor === index && current.focus === index && !composerOpen.value) {
        selection.value = null;
    } else {
        selection.value = { path, anchor: index, focus: index };
    }
}

function selectRows(path: string, fromIndex: number, toIndex: number): void {
    selection.value = { path, anchor: fromIndex, focus: toIndex };
}

function clearSelection(): void {
    selection.value = null;
    composerOpen.value = false;
}

function openComposer(): void {
    if (selection.value && props.permissions.can_run_review) {
        composerOpen.value = true;
    }
}

function questionAsked(): void {
    composerPrompt.value = '';
    clearSelection();
}

function isTyping(target: EventTarget | null): boolean {
    return (
        target instanceof HTMLElement &&
        (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) || !!target.closest('[role="dialog"]'))
    );
}

useEventListener(document, 'keydown', (event: KeyboardEvent) => {
    if (!props.isActive || !selection.value || isTyping(event.target) || event.metaKey || event.ctrlKey || event.altKey) {
        return;
    }

    if (event.key === 'a' && !composerOpen.value && props.permissions.can_run_review) {
        event.preventDefault();
        openComposer();
    } else if (event.key === 'Escape') {
        event.preventDefault();

        if (composerOpen.value) {
            composerOpen.value = false;
        } else {
            clearSelection();
        }
    }
});

/*
 * "Show in diff" from the Questions tab: make sure the file and line are rendered, then scroll to the line.
 */
let lastRevealNonce = 0;

watch(
    () => props.revealTarget,
    async (target) => {
        if (!target || target.nonce === lastRevealNonce) {
            return;
        }

        lastRevealNonce = target.nonce;

        if (!allFiles.value.some((file) => file.path === target.path)) {
            return;
        }

        if (!visibleFiles.value.some((file) => file.path === target.path)) {
            showAllFiles();
        }

        expanded[target.path] = true;
        diffRequested[target.path] = true;
        forceRendered[target.path] = true;

        await nextTick();
        requestAnimationFrame(() => {
            const row = document.querySelector<HTMLElement>(
                `[data-path="${CSS.escape(target.path)}"][data-side="${target.side}"][data-line="${target.line}"]`,
            );

            if (row) {
                row.scrollIntoView({ block: 'center', behavior: 'smooth' });
                row.animate([{ backgroundColor: 'rgb(139 92 246 / 0.35)' }, { backgroundColor: 'transparent' }], {
                    duration: 1800,
                    easing: 'ease-out',
                });
            } else {
                fileCard(target.path)?.scrollIntoView({ block: 'start', behavior: 'smooth' });
            }
        });
    },
    { immediate: true },
);
</script>

<template>
    <div class="space-y-3">
        <div
            v-if="files.error"
            class="flex items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
            role="alert"
        >
            <AlertTriangle class="mt-0.5 size-4 shrink-0" />
            <div class="min-w-0 flex-1">
                <p class="font-medium">Couldn't load the changed files from GitHub</p>
                <p class="mt-0.5 break-words text-xs opacity-90">{{ files.error }}</p>
            </div>
            <Button
                variant="outline"
                size="sm"
                class="shrink-0 border-amber-300 bg-transparent hover:bg-amber-100 dark:border-amber-500/40 dark:hover:bg-amber-500/20"
                :disabled="retrying"
                @click="retry"
            >
                <Loader2 v-if="retrying" class="animate-spin" />
                <RotateCcw v-else />
                Retry
            </Button>
        </div>

        <template v-if="allFiles.length > 0">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border bg-card px-3 py-2">
                <div class="flex items-center gap-2">
                    <span class="text-xs">
                        <span class="font-semibold tabular-nums">{{ viewedCount }}</span> of
                        <span class="tabular-nums">{{ allFiles.length }}</span> files viewed
                    </span>
                    <div
                        class="h-1.5 w-24 overflow-hidden rounded-full bg-muted"
                        role="progressbar"
                        :aria-valuenow="viewedCount"
                        :aria-valuemax="allFiles.length"
                        aria-label="Files viewed"
                    >
                        <div class="h-full rounded-full bg-emerald-500 transition-[width]" :style="{ width: `${viewedPercentage}%` }" />
                    </div>
                </div>
                <span class="font-mono text-xs tabular-nums">
                    <span class="text-emerald-600 dark:text-emerald-400">+{{ totalAdditions.toLocaleString() }}</span>
                    <span class="ml-1 text-red-600 dark:text-red-400">−{{ totalDeletions.toLocaleString() }}</span>
                </span>

                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <div class="relative">
                        <Search class="pointer-events-none absolute left-2 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            :model-value="filter"
                            placeholder="Filter files…"
                            class="h-8 w-44 pl-7 text-xs md:text-xs"
                            aria-label="Filter files by name"
                            @update:model-value="filter = String($event)"
                        />
                    </div>
                    <label class="flex cursor-pointer select-none items-center gap-1.5 text-xs">
                        <Switch :checked="hideViewed" @update:checked="hideViewed = $event" />
                        Hide viewed
                    </label>
                    <Button variant="ghost" size="icon" class="size-8" title="Expand all" @click="setAllExpanded(true)">
                        <ChevronsUpDown />
                        <span class="sr-only">Expand all</span>
                    </Button>
                    <Button variant="ghost" size="icon" class="size-8" title="Collapse all" @click="setAllExpanded(false)">
                        <ChevronsDownUp />
                        <span class="sr-only">Collapse all</span>
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="h-8 px-2 text-xs"
                        :class="{ 'bg-muted': showFileList }"
                        :aria-expanded="showFileList"
                        @click="showFileList = !showFileList"
                    >
                        <ListTree />
                        Files
                    </Button>
                </div>
            </div>

            <p
                v-if="viewedError"
                class="flex items-center gap-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"
                role="alert"
            >
                <AlertTriangle class="size-3.5 shrink-0" />
                <span class="flex-1">{{ viewedError }}</span>
                <button type="button" class="opacity-75 hover:opacity-100" @click="viewedError = null">
                    <X class="size-3.5" />
                    <span class="sr-only">Dismiss</span>
                </button>
            </p>
            <p v-else-if="syncWarning" class="flex items-center gap-2 px-1 text-xs text-muted-foreground">
                <Info class="size-3.5 shrink-0" />
                <span class="flex-1">{{ syncWarning }}</span>
                <button type="button" class="hover:text-foreground" @click="syncWarning = null">
                    <X class="size-3.5" />
                    <span class="sr-only">Dismiss</span>
                </button>
            </p>

            <p v-if="permissions.can_run_review" class="flex items-center gap-1.5 px-1 text-xs text-muted-foreground">
                <Sparkles class="size-3.5 shrink-0 text-violet-500" />
                Click a line number (shift-click for a range) or select code, then press
                <kbd class="rounded border px-1 font-mono text-[10px] text-foreground">a</kbd> to ask Claude about it.
            </p>

            <nav v-if="showFileList" class="max-h-72 overflow-y-auto rounded-lg border bg-card py-1" aria-label="Changed files">
                <button
                    v-for="file in visibleFiles"
                    :key="file.path"
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-1 text-left text-xs hover:bg-muted"
                    :title="file.path"
                    @click="jumpTo(file)"
                >
                    <Check v-if="viewed[file.path]" class="size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                    <span v-else class="size-3.5 shrink-0" />
                    <span
                        :class="
                            cn(
                                'w-3 shrink-0 text-center font-mono font-semibold',
                                file.status === 'added' && 'text-emerald-600 dark:text-emerald-400',
                                file.status === 'removed' && 'text-red-600 dark:text-red-400',
                                (file.status === 'renamed' || file.status === 'copied') && 'text-sky-600 dark:text-sky-400',
                                !['added', 'removed', 'renamed', 'copied'].includes(file.status) && 'text-amber-600 dark:text-amber-400',
                            )
                        "
                    >
                        {{ file.status === 'removed' ? 'D' : file.status.charAt(0).toUpperCase() }}
                    </span>
                    <span class="min-w-0 flex-1 truncate font-mono" :class="{ 'text-muted-foreground': viewed[file.path] }">{{ file.path }}</span>
                    <span
                        v-if="threadsForPath.get(file.path)?.length"
                        class="inline-flex shrink-0 items-center gap-0.5 text-violet-600 dark:text-violet-400"
                    >
                        <Sparkles class="size-3" />
                        {{ threadsForPath.get(file.path)?.length }}
                    </span>
                    <span class="shrink-0 font-mono tabular-nums">
                        <span class="text-emerald-600 dark:text-emerald-400">+{{ file.additions }}</span>
                        <span class="ml-1 text-red-600 dark:text-red-400">−{{ file.deletions }}</span>
                    </span>
                </button>
                <p v-if="visibleFiles.length === 0" class="px-3 py-2 text-xs text-muted-foreground">No files match.</p>
            </nav>

            <div class="space-y-3">
                <DiffFile
                    v-for="file in visibleFiles"
                    :key="file.path"
                    v-model:prompt="composerPrompt"
                    :file="file"
                    :pull-request="pullRequest"
                    :viewed="viewed[file.path] ?? false"
                    :changed-since-viewed="changedSinceViewed[file.path] ?? false"
                    :expanded="expanded[file.path] ?? true"
                    :diff-requested="diffRequested[file.path] ?? false"
                    :force-render="forceRendered[file.path] ?? false"
                    :threads="threadsForPath.get(file.path) ?? NO_THREADS"
                    :selection="selection?.path === file.path ? selection : null"
                    :composer-open="composerOpen && selection?.path === file.path"
                    :can-ask="permissions.can_run_review"
                    @toggle-viewed="(value: boolean) => setViewed(file, value)"
                    @toggle-expanded="expanded[file.path] = !(expanded[file.path] ?? true)"
                    @load-diff="diffRequested[file.path] = true"
                    @line-click="(index: number, extend: boolean) => selectLine(file.path, index, extend)"
                    @select-rows="(fromIndex: number, toIndex: number) => selectRows(file.path, fromIndex, toIndex)"
                    @open-composer="openComposer"
                    @close-composer="composerOpen = false"
                    @clear-selection="clearSelection"
                    @question-asked="questionAsked"
                />
            </div>

            <div v-if="visibleFiles.length === 0" class="rounded-lg border border-dashed px-6 py-8 text-center text-sm text-muted-foreground">
                No files match.
                <button type="button" class="font-medium text-foreground underline underline-offset-2" @click="showAllFiles">Show all files</button>
            </div>

            <p v-if="threadsOnRemovedFiles > 0" class="px-1 text-xs text-muted-foreground">
                {{ threadsOnRemovedFiles }} question thread{{ threadsOnRemovedFiles === 1 ? ' is' : 's are' }} on files no longer in this pull
                request. See the Questions tab.
            </p>
        </template>

        <div v-else-if="!files.error" class="rounded-lg border border-dashed px-6 py-10 text-center text-sm text-muted-foreground">
            This pull request has no changed files.
        </div>
    </div>
</template>
