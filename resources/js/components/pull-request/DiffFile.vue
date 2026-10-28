<script setup lang="ts">
import ExternalLink from '@/components/ExternalLink.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { getJson } from '@/lib/http';
import type { PendingReviewComment, PullRequest, PullRequestFile, PullRequestPermissions, ReplyDraft, ReviewFinding, ReviewThread } from '@/types';
import { Link } from '@inertiajs/vue3';
import { useIntersectionObserver } from '@vueuse/core';
import {
    ChevronRight,
    Clock,
    ExternalLink as ExternalLinkIcon,
    FileWarning,
    MessageSquare,
    MessageSquareCode,
    MessageSquarePlus,
    Sparkles,
} from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';
import AskClaudeComposer from './AskClaudeComposer.vue';
import type { QuestionThread } from './code-questions';
import CodeQuestionThread from './CodeQuestionThread.vue';
import {
    countPatchLines,
    formatLineRange,
    hiddenLinesAbove,
    lineKey,
    parsePatch,
    selectionFromRange,
    spansHunks,
    splitFileLines,
    withContext,
    type DiffGap,
} from './diff';
import DiffTable from './DiffTable.vue';
import InlineFinding from './InlineFinding.vue';
import LineCommentComposer from './LineCommentComposer.vue';
import PendingCommentCard from './PendingCommentCard.vue';
import ReviewThreadCard from './ReviewThreadCard.vue';

/** Diffs longer than this start collapsed behind a "Load diff" button. */
const LARGE_DIFF_LINES = 400;
const ROW_HEIGHT = 20;

/**
 * One changed file: a sticky header with its Viewed checkbox, and its diff with GitHub review threads and questions
 * shown under the lines they are about. The diff is only parsed and rendered once the file is expanded and scrolled near.
 */
const props = defineProps<{
    file: PullRequestFile;
    pullRequest: PullRequest;
    viewed: boolean;
    changedSinceViewed: boolean;
    expanded: boolean;
    /** The user asked to see a diff that is too large to show by default. */
    diffRequested: boolean;
    /** Render now instead of waiting for the file to scroll into view (e.g. to jump to a line). */
    forceRender: boolean;
    threads: QuestionThread[];
    /** GitHub review threads on this file. */
    reviewThreads: ReviewThread[];
    /** AI findings on this file that are still worth showing (not resolved or dismissed as not useful). */
    findings: ReviewFinding[];
    /** The user's comments on this file waiting to be posted with their review. */
    pendingComments: PendingReviewComment[];
    replyDrafts: ReplyDraft[];
    /** Selected rows in this file, if the selection is here. */
    selection: { anchor: number; focus: number } | null;
    /** The box open under the selection: a question for Claude or a comment for GitHub. */
    composer: 'ask' | 'comment' | null;
    permissions: PullRequestPermissions;
    layout: 'unified' | 'split';
}>();

const emit = defineEmits<{
    'toggle-viewed': [viewed: boolean];
    'toggle-expanded': [];
    'load-diff': [];
    'line-click': [index: number, extend: boolean];
    'select-rows': [fromIndex: number, toIndex: number];
    'open-composer': [composer: 'ask' | 'comment'];
    'close-composer': [];
    'clear-selection': [];
    'question-asked': [];
    'comment-added': [];
}>();

const prompt = defineModel<string>('prompt', { default: '' });
const comment = defineModel<string>('comment', { default: '' });

const canAsk = computed(() => props.permissions.can_run_review);

const root = ref<HTMLElement | null>(null);
const hasBeenNearViewport = ref(false);

const { stop: stopObserving } = useIntersectionObserver(
    root,
    ([entry]) => {
        if (entry?.isIntersecting) {
            hasBeenNearViewport.value = true;
            stopObserving();
        }
    },
    { rootMargin: '1200px 0px' },
);

const statusStyles: Record<PullRequestFile['status'], { label: string; badge: 'success' | 'danger' | 'info' | 'muted' }> = {
    added: { label: 'Added', badge: 'success' },
    removed: { label: 'Deleted', badge: 'danger' },
    renamed: { label: 'Renamed', badge: 'info' },
    copied: { label: 'Copied', badge: 'info' },
    modified: { label: 'Modified', badge: 'muted' },
    changed: { label: 'Changed', badge: 'muted' },
    unchanged: { label: 'Unchanged', badge: 'muted' },
};

const directory = computed(() => {
    const slash = props.file.path.lastIndexOf('/');

    return slash === -1 ? '' : props.file.path.slice(0, slash + 1);
});
const fileName = computed(() => props.file.path.slice(directory.value.length));

const lineCount = computed(() => countPatchLines(props.file.patch));
const isLarge = computed(() => lineCount.value > LARGE_DIFF_LINES);
const showsDiff = computed(() => props.expanded && props.file.patch !== null && (!isLarge.value || props.diffRequested));
const isRendered = computed(() => showsDiff.value && (hasBeenNearViewport.value || props.forceRender));

/** Parsed lazily: only when the diff is on screen or questions need placing. */
const lines = computed(() => (props.file.patch ? parsePatch(props.file.patch) : []));

/*
 * Unchanged lines around the diff. The file's text is fetched the first time a gap is opened, by its blob SHA, so it
 * is exactly the version the diff was made against. Deleted files have no new version, and new files are all diff.
 */
const fileLines = ref<string[] | null>(null);
const expanding = ref(false);
const expandError = ref<string | null>(null);
const revealed = reactive(new Set<DiffGap>());

const canExpand = computed(() => Boolean(props.file.sha) && props.file.status !== 'removed' && props.file.status !== 'added');

const hiddenAbove = computed(() =>
    canExpand.value ? new Map([...hiddenLinesAbove(lines.value)].filter(([index]) => !revealed.has(index))) : new Map<number, number>(),
);

const lastNewLine = computed(() => lines.value.reduce((last, line) => Math.max(last, line.newLine ?? 0), 0));

const canExpandEnd = computed(
    () =>
        canExpand.value && !revealed.has('end') && lines.value.length > 0 && (fileLines.value === null || fileLines.value.length > lastNewLine.value),
);

const displayLines = computed(() => (fileLines.value && revealed.size > 0 ? withContext(lines.value, fileLines.value, revealed) : lines.value));

async function expand(gap: DiffGap): Promise<void> {
    expandError.value = null;

    if (fileLines.value === null) {
        expanding.value = true;

        try {
            const { content } = await getJson<{ content: string }>(
                route('pull-requests.file-contents.show', { pullRequest: props.pullRequest.id, sha: props.file.sha }),
                'Could not load the rest of the file',
            );

            fileLines.value = splitFileLines(content);
        } catch (error) {
            expandError.value = error instanceof Error ? error.message : 'Could not load the rest of the file.';

            return;
        } finally {
            expanding.value = false;
        }
    }

    revealed.add(gap);
}

/** Row index by "RIGHT:42" key, for placing threads under the line they are on. */
const indexByKey = computed(() => {
    const indexes = new Map<string, number>();

    if (props.threads.length === 0 && props.reviewThreads.length === 0 && props.findings.length === 0 && props.pendingComments.length === 0) {
        return indexes;
    }

    for (const line of lines.value) {
        const key = lineKey(line);

        if (key) {
            indexes.set(key, line.index);
        }
    }

    return indexes;
});

/** Threads under the row their last line is on; the rest refer to lines no longer in the diff. */
const placement = computed(() => {
    const byIndex = new Map<number, QuestionThread[]>();
    const orphans: QuestionThread[] = [];

    for (const thread of props.threads) {
        const index = indexByKey.value.get(`${thread.root.side}:${thread.root.end_line}`);

        if (index === undefined) {
            orphans.push(thread);
        } else {
            byIndex.set(index, [...(byIndex.get(index) ?? []), thread]);
        }
    }

    return { byIndex, orphans };
});

/** GitHub review threads under the row they are on. Outdated threads are on an earlier version of the diff, so they stay in the Conversation tab. */
const reviewThreadPlacement = computed(() => {
    const byIndex = new Map<number, ReviewThread[]>();

    for (const thread of props.reviewThreads) {
        const index = thread.is_outdated || thread.line === null ? undefined : indexByKey.value.get(`${thread.side ?? 'RIGHT'}:${thread.line}`);

        if (index !== undefined) {
            byIndex.set(index, [...(byIndex.get(index) ?? []), thread]);
        }
    }

    return byIndex;
});

/** AI findings under the line they are on. Findings on lines outside the diff, or on the whole file, are listed above it. */
const findingPlacement = computed(() => {
    const byIndex = new Map<number, ReviewFinding[]>();
    const orphans: ReviewFinding[] = [];

    for (const finding of props.findings) {
        const index = finding.line === null ? undefined : indexByKey.value.get(`RIGHT:${finding.line}`);

        if (index === undefined) {
            orphans.push(finding);
        } else {
            byIndex.set(index, [...(byIndex.get(index) ?? []), finding]);
        }
    }

    return { byIndex, orphans };
});

/** Pending comments under the line they end on. Ones whose lines left the diff (after new commits) are listed above it. */
const pendingPlacement = computed(() => {
    const byIndex = new Map<number, PendingReviewComment[]>();
    const orphans: PendingReviewComment[] = [];

    for (const comment of props.pendingComments) {
        const index = indexByKey.value.get(`${comment.side}:${comment.line}`);

        if (index === undefined) {
            orphans.push(comment);
        } else {
            byIndex.set(index, [...(byIndex.get(index) ?? []), comment]);
        }
    }

    return { byIndex, orphans };
});

const selectionDetails = computed(() => (props.selection ? selectionFromRange(lines.value, props.selection.anchor, props.selection.focus) : null));

const selectedRange = computed<[number, number] | null>(() =>
    selectionDetails.value ? [selectionDetails.value.startIndex, selectionDetails.value.endIndex] : null,
);

const questionTarget = computed(() =>
    selectionDetails.value
        ? {
              path: props.file.path,
              side: selectionDetails.value.side,
              startLine: selectionDetails.value.startLine,
              endLine: selectionDetails.value.endLine,
              selectedCode: selectionDetails.value.selectedCode,
          }
        : null,
);

const selectionSpansHunks = computed(() =>
    selectionDetails.value ? spansHunks(lines.value, selectionDetails.value.startIndex, selectionDetails.value.endIndex) : false,
);

const afterIndexes = computed(() => {
    const indexes = new Set([
        ...placement.value.byIndex.keys(),
        ...reviewThreadPlacement.value.keys(),
        ...findingPlacement.value.byIndex.keys(),
        ...pendingPlacement.value.byIndex.keys(),
    ]);

    if (selectionDetails.value) {
        indexes.add(selectionDetails.value.endIndex);
    }

    return indexes;
});

/** Marking a file viewed collapses it; the files panel then scrolls on to the next file. */
function toggleViewed(value: boolean): void {
    emit('toggle-viewed', value);
}
</script>

<template>
    <section
        ref="root"
        :data-file-path="file.path"
        class="scroll-mt-[calc(var(--files-toolbar-height,0px)+0.5rem)] overflow-clip rounded-lg border bg-card text-card-foreground"
    >
        <header
            class="sticky top-[var(--files-toolbar-height,0px)] z-10 flex items-center gap-2 bg-card/95 px-3 py-2 backdrop-blur supports-[backdrop-filter]:bg-card/80"
            :class="expanded ? 'border-b' : ''"
        >
            <button
                type="button"
                class="flex min-w-0 flex-1 items-center gap-2 text-left"
                :aria-expanded="expanded"
                :title="file.path"
                @click="emit('toggle-expanded')"
            >
                <ChevronRight class="size-4 shrink-0 text-muted-foreground transition-transform" :class="{ 'rotate-90': expanded }" />
                <span class="flex min-w-0 items-baseline font-mono text-xs">
                    <template v-if="file.previous_path && file.previous_path !== file.path">
                        <span class="min-w-0 truncate text-muted-foreground line-through decoration-muted-foreground/40">{{
                            file.previous_path
                        }}</span>
                        <span class="shrink-0 px-1 text-muted-foreground">→</span>
                    </template>
                    <span class="min-w-0 truncate text-muted-foreground">{{ directory }}</span>
                    <span class="shrink-0 font-semibold text-foreground">{{ fileName }}</span>
                </span>
            </button>

            <div class="flex shrink-0 items-center gap-2">
                <Badge v-if="changedSinceViewed" variant="warning" class="px-1.5 text-[10px]">Changed since viewed</Badge>
                <Badge
                    v-if="reviewThreads.length"
                    variant="outline"
                    class="px-1.5 text-[10px]"
                    :title="`${reviewThreads.length} review thread${reviewThreads.length === 1 ? '' : 's'} on GitHub`"
                >
                    <MessageSquare />
                    {{ reviewThreads.length }}
                </Badge>
                <Badge
                    v-if="pendingComments.length"
                    variant="outline"
                    class="border-primary/50 px-1.5 text-[10px]"
                    :title="`${pendingComments.length} pending comment${pendingComments.length === 1 ? '' : 's'} in your review`"
                >
                    <Clock />
                    {{ pendingComments.length }}
                </Badge>
                <Badge
                    v-if="findings.length"
                    variant="outline"
                    class="px-1.5 text-[10px]"
                    :title="`${findings.length} AI finding${findings.length === 1 ? '' : 's'}`"
                >
                    <Sparkles class="text-violet-500" />
                    {{ findings.length }}
                </Badge>
                <Badge
                    v-if="threads.length"
                    variant="outline"
                    class="px-1.5 text-[10px]"
                    :title="`${threads.length} question${threads.length === 1 ? '' : 's'} for Claude`"
                >
                    <MessageSquareCode class="text-violet-500" />
                    {{ threads.length }}
                </Badge>
                <Badge :variant="statusStyles[file.status].badge" class="hidden px-1.5 text-[10px] xl:inline-flex">{{
                    statusStyles[file.status].label
                }}</Badge>
                <span class="hidden font-mono text-xs tabular-nums xl:inline">
                    <span class="text-emerald-600 dark:text-emerald-400">+{{ file.additions }}</span>
                    <span class="ml-1 text-red-600 dark:text-red-400">−{{ file.deletions }}</span>
                </span>
                <ExternalLink
                    :href="`${pullRequest.html_url}/files`"
                    class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                    title="Open the files on GitHub"
                >
                    <ExternalLinkIcon class="size-3.5" />
                    <span class="sr-only">Open on GitHub</span>
                </ExternalLink>
                <label
                    class="flex cursor-pointer select-none items-center gap-1.5 rounded-md border px-2 py-1 text-xs transition-colors"
                    :class="
                        viewed
                            ? 'border-emerald-500/40 bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300'
                            : 'hover:bg-muted'
                    "
                >
                    <Checkbox class="size-3.5" :checked="viewed" :aria-label="`Viewed ${file.path}`" @update:checked="toggleViewed" />
                    Viewed
                </label>
            </div>
        </header>

        <div v-if="expanded">
            <div v-if="pendingPlacement.orphans.length" class="space-y-2 border-b bg-muted/30 px-3 py-3">
                <p class="text-xs font-medium text-muted-foreground">Pending comments on lines no longer in the diff</p>
                <PendingCommentCard v-for="comment in pendingPlacement.orphans" :key="comment.id" :comment="comment" show-location />
            </div>

            <div v-if="findingPlacement.orphans.length" class="space-y-2 border-b bg-muted/30 px-3 py-3">
                <p class="text-xs font-medium text-muted-foreground">
                    {{ file.patch ? 'AI findings on lines outside the diff' : 'AI findings on this file' }}
                </p>
                <InlineFinding v-for="finding in findingPlacement.orphans" :key="finding.id" :finding="finding" />
            </div>

            <div v-if="placement.orphans.length" class="space-y-2 border-b bg-muted/30 px-3 py-3">
                <p class="text-xs font-medium text-muted-foreground">
                    {{ file.patch ? 'Questions on lines no longer in the diff' : 'Questions on this file' }}
                </p>
                <CodeQuestionThread
                    v-for="thread in placement.orphans"
                    :key="thread.root.id"
                    :thread="thread"
                    :pull-request="pullRequest"
                    :can-ask="canAsk"
                    :default-open="false"
                    :can-show-in-diff="false"
                    show-location
                />
            </div>

            <div v-if="file.patch === null" class="flex flex-col items-center gap-1 px-4 py-6 text-center text-sm text-muted-foreground">
                <FileWarning class="size-5" />
                <p>Diff not available (binary or too large).</p>
                <ExternalLink :href="`${pullRequest.html_url}/files`" class="font-medium text-foreground underline underline-offset-2">
                    View it on GitHub
                </ExternalLink>
            </div>

            <div v-else-if="isLarge && !diffRequested" class="flex flex-col items-center gap-2 px-4 py-6 text-center text-sm text-muted-foreground">
                <p>This diff is large ({{ lineCount.toLocaleString() }} lines), so it isn't shown automatically.</p>
                <Button variant="outline" size="sm" @click="emit('load-diff')">Load diff</Button>
            </div>

            <div v-else-if="!isRendered" class="bg-muted/10" :style="{ height: `${Math.min(lineCount, 2000) * ROW_HEIGHT}px` }" />

            <template v-else>
                <p
                    v-if="expandError"
                    class="border-b border-red-200 bg-red-50 px-3 py-2 text-xs text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"
                    role="alert"
                >
                    {{ expandError }}
                </p>

                <DiffTable
                    :path="file.path"
                    :lines="displayLines"
                    :layout="layout"
                    :hidden-above="hiddenAbove"
                    :can-expand-end="canExpandEnd"
                    :expanding="expanding"
                    :selected-range="selectedRange"
                    :after-indexes="afterIndexes"
                    @line-click="(index: number, extend: boolean) => emit('line-click', index, extend)"
                    @select-rows="(fromIndex: number, toIndex: number) => emit('select-rows', fromIndex, toIndex)"
                    @expand="expand"
                >
                    <template #after="{ index }">
                        <div v-if="selectionDetails && questionTarget && selectionDetails.endIndex === index" class="border-y bg-background">
                            <div v-if="composer === 'ask' && canAsk" class="px-3 py-3">
                                <AskClaudeComposer
                                    v-model:prompt="prompt"
                                    :pull-request-id="pullRequest.id"
                                    :target="questionTarget"
                                    :can-ask="canAsk"
                                    @cancel="emit('close-composer')"
                                    @submitted="emit('question-asked')"
                                />
                            </div>
                            <div v-else-if="composer === 'comment'" class="px-3 py-3">
                                <LineCommentComposer
                                    v-model:body="comment"
                                    :pull-request-id="pullRequest.id"
                                    :target="questionTarget"
                                    :can-comment="permissions.can_reply_to_comments"
                                    :spans-hunks="selectionSpansHunks"
                                    @cancel="emit('close-composer')"
                                    @submitted="emit('comment-added')"
                                />
                            </div>
                            <div v-else class="flex flex-wrap items-center gap-2 py-1.5 pl-[7.25rem] pr-3">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="h-7 px-2.5 text-xs shadow-md [&_svg]:size-3.5"
                                    @click="emit('open-composer', 'comment')"
                                >
                                    <MessageSquarePlus />
                                    Comment
                                    <kbd class="rounded border px-1 font-mono text-[10px]">c</kbd>
                                </Button>
                                <Button
                                    size="sm"
                                    class="h-7 px-2.5 text-xs shadow-md [&_svg]:size-3.5"
                                    :disabled="!canAsk"
                                    @click="emit('open-composer', 'ask')"
                                >
                                    <Sparkles />
                                    Ask Claude
                                    <kbd class="rounded border border-primary-foreground/30 px-1 font-mono text-[10px]">a</kbd>
                                </Button>
                                <span class="text-xs text-muted-foreground">
                                    {{ formatLineRange(selectionDetails.startLine, selectionDetails.endLine) }} selected
                                </span>
                                <Link v-if="!canAsk" :href="route('settings.claude')" class="text-xs font-medium underline underline-offset-2">
                                    Connect Claude in Settings
                                </Link>
                                <button
                                    type="button"
                                    class="ml-auto text-xs text-muted-foreground hover:text-foreground"
                                    @click="emit('clear-selection')"
                                >
                                    Clear <kbd class="font-mono">Esc</kbd>
                                </button>
                            </div>
                        </div>
                        <div v-if="findingPlacement.byIndex.has(index)" class="space-y-2 border-y bg-muted/30 px-3 py-3">
                            <InlineFinding v-for="finding in findingPlacement.byIndex.get(index)" :key="finding.id" :finding="finding" />
                        </div>
                        <div v-if="pendingPlacement.byIndex.has(index)" class="space-y-2 border-y bg-muted/30 px-3 py-3">
                            <PendingCommentCard v-for="comment in pendingPlacement.byIndex.get(index)" :key="comment.id" :comment="comment" />
                        </div>
                        <div v-if="reviewThreadPlacement.has(index)" class="space-y-2 border-y bg-muted/30 px-3 py-3">
                            <ReviewThreadCard
                                v-for="thread in reviewThreadPlacement.get(index)"
                                :key="thread.id"
                                :thread="thread"
                                :pull-request-id="pullRequest.id"
                                :permissions="permissions"
                                :reply-drafts="replyDrafts"
                                :show-diff-hunk="false"
                            />
                        </div>
                        <div v-if="placement.byIndex.has(index)" class="space-y-2 border-y bg-muted/30 px-3 py-3">
                            <CodeQuestionThread
                                v-for="thread in placement.byIndex.get(index)"
                                :key="thread.root.id"
                                :thread="thread"
                                :pull-request="pullRequest"
                                :can-ask="canAsk"
                            />
                        </div>
                    </template>
                </DiffTable>
            </template>
        </div>
    </section>
</template>
