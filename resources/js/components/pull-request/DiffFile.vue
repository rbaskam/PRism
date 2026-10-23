<script setup lang="ts">
import ExternalLink from '@/components/ExternalLink.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import type { PullRequest, PullRequestFile, PullRequestPermissions, ReplyDraft, ReviewThread } from '@/types';
import { Link } from '@inertiajs/vue3';
import { useIntersectionObserver } from '@vueuse/core';
import { ChevronRight, ExternalLink as ExternalLinkIcon, FileWarning, MessageSquare, MessageSquarePlus, Sparkles } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import AskClaudeComposer from './AskClaudeComposer.vue';
import type { QuestionThread } from './code-questions';
import CodeQuestionThread from './CodeQuestionThread.vue';
import { countPatchLines, formatLineRange, lineKey, parsePatch, selectionFromRange, spansHunks } from './diff';
import DiffTable from './DiffTable.vue';
import LineCommentComposer from './LineCommentComposer.vue';
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
    replyDrafts: ReplyDraft[];
    /** Selected rows in this file, if the selection is here. */
    selection: { anchor: number; focus: number } | null;
    /** The box open under the selection: a question for Claude or a comment for GitHub. */
    composer: 'ask' | 'comment' | null;
    permissions: PullRequestPermissions;
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

/** Row index by "RIGHT:42" key, for placing threads under the line they are on. */
const indexByKey = computed(() => {
    const indexes = new Map<string, number>();

    if (props.threads.length === 0 && props.reviewThreads.length === 0) {
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
    const indexes = new Set([...placement.value.byIndex.keys(), ...reviewThreadPlacement.value.keys()]);

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
                <Badge v-if="threads.length" variant="outline" class="px-1.5 text-[10px]" :title="`${threads.length} question thread(s)`">
                    <Sparkles class="text-violet-500" />
                    {{ threads.length }}
                </Badge>
                <Badge :variant="statusStyles[file.status].badge" class="hidden px-1.5 text-[10px] sm:inline-flex">{{
                    statusStyles[file.status].label
                }}</Badge>
                <span class="hidden font-mono text-xs tabular-nums sm:inline">
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
                    <Checkbox class="size-3.5" :checked="viewed" @update:checked="toggleViewed" />
                    Viewed
                </label>
            </div>
        </header>

        <div v-if="expanded">
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

            <DiffTable
                v-else
                :path="file.path"
                :lines="lines"
                :selected-range="selectedRange"
                :after-indexes="afterIndexes"
                @line-click="(index: number, extend: boolean) => emit('line-click', index, extend)"
                @select-rows="(fromIndex: number, toIndex: number) => emit('select-rows', fromIndex, toIndex)"
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
        </div>
    </section>
</template>
