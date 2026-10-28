<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { sendJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import type {
    CodeQuestion,
    PendingReviewComment,
    PullRequest,
    PullRequestFile,
    PullRequestFiles,
    PullRequestPermissions,
    ReplyDraft,
    ReviewFinding,
    ReviewThread,
} from '@/types';
import { router } from '@inertiajs/vue3';
import { useElementBounding, useEventListener, useStorage } from '@vueuse/core';
import {
    AlertTriangle,
    Check,
    ChevronsDownUp,
    ChevronsUpDown,
    Columns2,
    Info,
    ListTree,
    Loader2,
    MessageSquareCode,
    MessageSquarePlus,
    RotateCcw,
    Rows2,
    Search,
    Sparkles,
    X,
} from 'lucide-vue-next';
import { computed, nextTick, reactive, ref, useTemplateRef, watch } from 'vue';
import { groupQuestionThreads, threadsByPath, type QuestionThread, type RevealTarget } from './code-questions';
import DiffFile from './DiffFile.vue';

/**
 * The pull request's changed files, GitHub style: mark files as viewed, filter them, and select lines to comment on
 * or ask Claude about.
 */
const props = defineProps<{
    files: PullRequestFiles;
    pullRequest: PullRequest;
    codeQuestions: CodeQuestion[];
    /** GitHub review threads, from the conversation once it has loaded. */
    reviewThreads: ReviewThread[];
    /** AI findings, shown under the lines they are about. */
    findings: ReviewFinding[];
    /** The user's comments waiting to be posted with their review. */
    pendingComments: PendingReviewComment[];
    replyDrafts: ReplyDraft[];
    permissions: PullRequestPermissions;
    /** Whether the Files changed tab is showing, so keyboard shortcuts only apply there. */
    isActive: boolean;
    revealTarget: RevealTarget | null;
}>();

const NO_THREADS: QuestionThread[] = [];
const NO_REVIEW_THREADS: ReviewThread[] = [];
const NO_FINDINGS: ReviewFinding[] = [];
const NO_PENDING_COMMENTS: PendingReviewComment[] = [];

const viewed = reactive<Record<string, boolean>>({});
const changedSinceViewed = reactive<Record<string, boolean>>({});
const expanded = reactive<Record<string, boolean>>({});
const diffRequested = reactive<Record<string, boolean>>({});
const forceRendered = reactive<Record<string, boolean>>({});

const filter = ref('');
/** One column (GitHub's unified view) or side by side, remembered on this computer. */
const layout = useStorage<'unified' | 'split'>('prism.diff-layout', 'unified');
const hideViewed = ref(false);
const showFileList = ref(props.files.files.length <= 15);
const viewedError = ref<string | null>(null);
const syncWarning = ref<string | null>(null);
const retrying = ref(false);

const selection = ref<{ path: string; anchor: number; focus: number } | null>(null);
const composer = ref<'ask' | 'comment' | null>(null);
const composerPrompt = ref('');
const commentBody = ref('');

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

/*
 * The toolbar with the viewed progress sticks to the top while scrolling. Its height is shared as a CSS variable so
 * each file's sticky header, and any file scrolled to, lines up just below it.
 */
const toolbar = useTemplateRef<HTMLElement>('toolbar');
const { height: toolbarHeight } = useElementBounding(toolbar);
const stickyOffset = computed(() => `${Math.ceil(toolbarHeight.value)}px`);

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

const pendingCommentsForPath = computed(() => {
    const byPath = new Map<string, PendingReviewComment[]>();

    for (const comment of props.pendingComments) {
        byPath.set(comment.path, [...(byPath.get(comment.path) ?? []), comment]);
    }

    return byPath;
});

/** Findings worth showing in the diff: resolved ones and ones marked not useful stay in the AI findings tab. */
const findingsForPath = computed(() => {
    const byPath = new Map<string, ReviewFinding[]>();

    for (const finding of props.findings) {
        if (finding.path && !finding.is_resolved && finding.status !== 'not_useful') {
            byPath.set(finding.path, [...(byPath.get(finding.path) ?? []), finding]);
        }
    }

    return byPath;
});
const reviewThreadsForPath = computed(() => {
    const byPath = new Map<string, ReviewThread[]>();

    for (const thread of props.reviewThreads) {
        byPath.set(thread.path, [...(byPath.get(thread.path) ?? []), thread]);
    }

    return byPath;
});
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

        showNextUnviewed(file);
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

/**
 * After a file is marked viewed (and collapses), bring the next file that hasn't been viewed to the top, so a long
 * pull request can be reviewed file after file without scrolling. With nothing left after it, keep its header in view.
 */
function showNextUnviewed(file: PullRequestFile): void {
    const index = allFiles.value.findIndex((candidate) => candidate.path === file.path);
    const next = allFiles.value.slice(index + 1).find((candidate) => !viewed[candidate.path] && visibleFiles.value.includes(candidate));

    if (next) {
        expanded[next.path] = true;
    }

    nextTick(() => {
        if (next) {
            fileCard(next.path)?.scrollIntoView({ block: 'start', behavior: 'smooth' });

            return;
        }

        const card = fileCard(file.path);

        if (card && card.getBoundingClientRect().top < toolbarHeight.value) {
            card.scrollIntoView({ block: 'start' });
        }
    });
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
    } else if (current?.path === path && current.anchor === index && current.focus === index && composer.value === null) {
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
    composer.value = null;
}

/** Asking Claude needs Claude connected. The comment box opens either way, and says when commenting is turned off. */
function openComposer(type: 'ask' | 'comment'): void {
    if (selection.value && (type === 'comment' || props.permissions.can_run_review)) {
        composer.value = type;
    }
}

function questionAsked(): void {
    composerPrompt.value = '';
    clearSelection();
}

function commentAdded(): void {
    commentBody.value = '';
    clearSelection();
}

function isTyping(target: EventTarget | null): boolean {
    return (
        target instanceof HTMLElement &&
        (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) || !!target.closest('[role="dialog"]'))
    );
}

/** The file whose header is at the top of the list, or the first one when the list hasn't been scrolled to yet. */
function currentFileIndex(): number {
    let current = -1;

    visibleFiles.value.forEach((file, index) => {
        const top = fileCard(file.path)?.getBoundingClientRect().top;

        if (top !== undefined && top <= toolbarHeight.value + 16) {
            current = index;
        }
    });

    return current;
}

/** j and k move to the next and previous file, like the findings list. */
function moveToFile(step: 1 | -1): void {
    const files = visibleFiles.value;
    const target = files[Math.min(files.length - 1, Math.max(0, currentFileIndex() + step))];

    if (target) {
        jumpTo(target);
    }
}

/** v marks the file at the top as viewed (or not), which moves on to the next file. */
function toggleCurrentViewed(): void {
    const file = visibleFiles.value[Math.max(0, currentFileIndex())];

    if (file) {
        setViewed(file, !viewed[file.path]);
    }
}

useEventListener(document, 'keydown', (event: KeyboardEvent) => {
    if (!props.isActive || isTyping(event.target) || event.metaKey || event.ctrlKey || event.altKey) {
        return;
    }

    if (!selection.value || composer.value === null) {
        const fileShortcuts: Record<string, () => void> = { j: () => moveToFile(1), k: () => moveToFile(-1), v: toggleCurrentViewed };

        if (fileShortcuts[event.key] && !(event.target instanceof HTMLElement && event.target.closest('[data-finding-id]'))) {
            event.preventDefault();
            fileShortcuts[event.key]();

            return;
        }
    }

    if (!selection.value) {
        return;
    }

    if (event.key === 'a' && composer.value === null && props.permissions.can_run_review) {
        event.preventDefault();
        openComposer('ask');
    } else if (event.key === 'c' && composer.value === null) {
        event.preventDefault();
        openComposer('comment');
    } else if (event.key === 'Escape') {
        event.preventDefault();

        if (composer.value !== null) {
            composer.value = null;
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
                row.animate([{ backgroundColor: 'hsl(var(--primary) / 0.3)' }, { backgroundColor: 'transparent' }], {
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
    <div class="space-y-3" :style="{ '--files-toolbar-height': stickyOffset }">
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
            <div
                ref="toolbar"
                role="toolbar"
                aria-label="Changed files"
                class="sticky top-0 z-20 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border bg-card px-3 py-2 shadow-sm"
            >
                <div class="flex items-center gap-2">
                    <span v-if="viewedCount === allFiles.length" class="flex items-center gap-1 text-xs font-medium">
                        <Check class="size-3.5 text-emerald-600 dark:text-emerald-400" />
                        {{ allFiles.length === 1 ? 'File viewed' : `All ${allFiles.length} files viewed` }}
                    </span>
                    <span v-else class="text-xs">
                        <span class="font-semibold tabular-nums">{{ viewedCount }}</span> of <span class="tabular-nums">{{ allFiles.length }}</span>
                        {{ allFiles.length === 1 ? 'file' : 'files' }} viewed
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
                    <div class="inline-flex rounded-md border p-0.5" role="group" aria-label="Diff layout">
                        <button
                            v-for="option in [
                                { value: 'unified', label: 'Unified', icon: Rows2 },
                                { value: 'split', label: 'Side by side', icon: Columns2 },
                            ] as const"
                            :key="option.value"
                            type="button"
                            class="inline-flex size-7 items-center justify-center rounded-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring [&_svg]:size-3.5"
                            :class="
                                layout === option.value
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:bg-accent hover:text-foreground'
                            "
                            :aria-pressed="layout === option.value"
                            :title="option.label"
                            @click="layout = option.value"
                        >
                            <component :is="option.icon" />
                            <span class="sr-only">{{ option.label }}</span>
                        </button>
                    </div>
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

            <p class="flex items-start gap-1.5 px-1 text-xs text-muted-foreground">
                <MessageSquarePlus class="mt-px size-3.5 shrink-0" />
                <span>
                    Click a line number (shift-click for a range) or select code, then press
                    <kbd class="rounded border px-1 font-mono text-[10px] text-foreground">c</kbd> to comment on it<span
                        v-if="permissions.can_run_review"
                    >
                        or <kbd class="rounded border px-1 font-mono text-[10px] text-foreground">a</kbd> to ask Claude about it</span
                    >. In a focused diff, <kbd class="rounded border px-1 font-mono text-[10px] text-foreground">↑</kbd>
                    <kbd class="rounded border px-1 font-mono text-[10px] text-foreground">↓</kbd> select lines.
                    <kbd class="rounded border px-1 font-mono text-[10px] text-foreground">j</kbd>
                    <kbd class="rounded border px-1 font-mono text-[10px] text-foreground">k</kbd> move between files and
                    <kbd class="rounded border px-1 font-mono text-[10px] text-foreground">v</kbd> marks one viewed.
                </span>
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
                        v-if="findingsForPath.get(file.path)?.length"
                        class="inline-flex shrink-0 items-center gap-0.5 text-violet-600 dark:text-violet-400"
                        :title="`${findingsForPath.get(file.path)?.length} AI finding(s)`"
                    >
                        <Sparkles class="size-3" />
                        {{ findingsForPath.get(file.path)?.length }}
                    </span>
                    <span
                        v-if="threadsForPath.get(file.path)?.length"
                        class="inline-flex shrink-0 items-center gap-0.5 text-violet-600 dark:text-violet-400"
                        :title="`${threadsForPath.get(file.path)?.length} question(s) for Claude`"
                    >
                        <MessageSquareCode class="size-3" />
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
                    v-model:comment="commentBody"
                    :file="file"
                    :pull-request="pullRequest"
                    :viewed="viewed[file.path] ?? false"
                    :changed-since-viewed="changedSinceViewed[file.path] ?? false"
                    :expanded="expanded[file.path] ?? true"
                    :diff-requested="diffRequested[file.path] ?? false"
                    :force-render="forceRendered[file.path] ?? false"
                    :threads="threadsForPath.get(file.path) ?? NO_THREADS"
                    :review-threads="reviewThreadsForPath.get(file.path) ?? NO_REVIEW_THREADS"
                    :findings="findingsForPath.get(file.path) ?? NO_FINDINGS"
                    :pending-comments="pendingCommentsForPath.get(file.path) ?? NO_PENDING_COMMENTS"
                    :reply-drafts="replyDrafts"
                    :selection="selection?.path === file.path ? selection : null"
                    :composer="selection?.path === file.path ? composer : null"
                    :permissions="permissions"
                    :layout="layout"
                    @toggle-viewed="(value: boolean) => setViewed(file, value)"
                    @toggle-expanded="expanded[file.path] = !(expanded[file.path] ?? true)"
                    @load-diff="diffRequested[file.path] = true"
                    @line-click="(index: number, extend: boolean) => selectLine(file.path, index, extend)"
                    @select-rows="(fromIndex: number, toIndex: number) => selectRows(file.path, fromIndex, toIndex)"
                    @open-composer="openComposer"
                    @close-composer="composer = null"
                    @clear-selection="clearSelection"
                    @question-asked="questionAsked"
                    @comment-added="commentAdded"
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
