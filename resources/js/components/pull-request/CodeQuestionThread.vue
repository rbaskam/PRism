<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDuration, timeAgo } from '@/lib/pull-requests';
import type { CodeQuestion, PullRequest } from '@/types';
import { router } from '@inertiajs/vue3';
import { useClipboard } from '@vueuse/core';
import {
    AlertTriangle,
    Check,
    ChevronRight,
    Clock,
    Copy,
    Crosshair,
    GitCommitHorizontal,
    Loader2,
    MessageSquarePlus,
    RotateCcw,
    Sparkles,
    Trash2,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import AskClaudeComposer from './AskClaudeComposer.vue';
import type { QuestionThread } from './code-questions';
import { formatLineRange } from './diff';
import DiffHunk from './DiffHunk.vue';
import ElapsedTime from './ElapsedTime.vue';
import MarkdownText from './MarkdownText.vue';
import { failedWithFlash, formatCost, formatDateTime, pageVisit } from './utils';

/**
 * A question about highlighted lines with Claude's answers and any follow-ups.
 */
const props = withDefaults(
    defineProps<{
        thread: QuestionThread;
        pullRequest: PullRequest;
        canAsk: boolean;
        /** Show the file and lines in the header and a "Show in diff" link (used outside the diff). */
        showLocation?: boolean;
        /** Whether the lines can still be found in the diff (for the "Show in diff" link). */
        canShowInDiff?: boolean;
        defaultOpen?: boolean;
    }>(),
    {
        showLocation: false,
        canShowInDiff: true,
        defaultOpen: true,
    },
);

const emit = defineEmits<{
    'show-in-diff': [];
}>();

const open = ref(props.defaultOpen);
const confirmingDelete = ref(false);
const deleting = ref(false);
const retryingId = ref<number | null>(null);
const followUpOpen = ref(false);
const followUpPrompt = ref('');
const copiedId = ref<number | null>(null);

const { copy } = useClipboard({ legacy: true });

const root = computed(() => props.thread.root);
const latest = computed(() => props.thread.entries[props.thread.entries.length - 1]);
const target = computed(() => ({
    path: root.value.path,
    side: root.value.side,
    startLine: root.value.start_line,
    endLine: root.value.end_line,
    selectedCode: root.value.selected_code,
}));

const statusLabel = computed(() => {
    switch (latest.value.status) {
        case 'queued':
            return { label: 'Queued', badge: 'muted' as const };
        case 'running':
            return { label: 'Thinking', badge: 'info' as const };
        case 'failed':
            return { label: 'Failed', badge: 'danger' as const };
        default:
            return { label: 'Answered', badge: 'success' as const };
    }
});

function isOnEarlierCommit(question: CodeQuestion): boolean {
    return question.head_sha !== null && props.pullRequest.head_sha !== null && question.head_sha !== props.pullRequest.head_sha;
}

async function copySuggestion(question: CodeQuestion): Promise<void> {
    if (!question.suggested_code) {
        return;
    }

    await copy(question.suggested_code);
    copiedId.value = question.id;
    setTimeout(() => {
        if (copiedId.value === question.id) {
            copiedId.value = null;
        }
    }, 1500);
}

/**
 * Asks the same question again. The failed attempt is removed once the new one is queued, unless it starts
 * a thread that has follow-ups (then the retry joins that thread instead).
 */
function retry(question: CodeQuestion): void {
    const isRoot = question.id === root.value.id;
    const hasFollowUps = props.thread.entries.length > 1;
    const parentId = isRoot ? (hasFollowUps ? question.id : null) : root.value.id;
    let removeFailedAttempt = false;

    router.post(
        route('pull-requests.code-questions.store', { pullRequest: props.pullRequest.id }),
        {
            path: question.path,
            side: question.side,
            start_line: question.start_line,
            end_line: question.end_line,
            selected_code: question.selected_code,
            prompt: question.prompt,
            ...(parentId ? { parent_id: parentId } : {}),
        },
        pageVisit({
            onStart: () => (retryingId.value = question.id),
            onSuccess: (page) => (removeFailedAttempt = !failedWithFlash(page) && !(isRoot && hasFollowUps)),
            onFinish: () => {
                retryingId.value = null;

                if (removeFailedAttempt) {
                    router.delete(route('code-questions.destroy', { codeQuestion: question.id }), pageVisit());
                }
            },
        }),
    );
}

function destroy(): void {
    router.delete(
        route('code-questions.destroy', { codeQuestion: root.value.id }),
        pageVisit({
            onStart: () => (deleting.value = true),
            onFinish: () => {
                deleting.value = false;
                confirmingDelete.value = false;
            },
        }),
    );
}
</script>

<template>
    <div class="overflow-hidden rounded-lg border bg-card text-card-foreground shadow-sm">
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 px-3 py-2" :class="{ 'border-b': open }">
            <button type="button" class="flex min-w-0 grow basis-48 items-center gap-2 text-left" :aria-expanded="open" @click="open = !open">
                <ChevronRight class="size-4 shrink-0 text-muted-foreground transition-transform" :class="{ 'rotate-90': open }" />
                <Sparkles class="size-3.5 shrink-0 text-violet-500" />
                <span v-if="showLocation" class="min-w-0 truncate font-mono text-xs" :title="root.location">{{ root.location }}</span>
                <span v-else class="shrink-0 text-xs font-medium">
                    Claude on {{ formatLineRange(root.start_line, root.end_line) }}{{ root.side === 'LEFT' ? ' (removed)' : '' }}
                </span>
                <span v-if="!open || showLocation" class="min-w-0 truncate text-xs text-muted-foreground">— {{ root.prompt }}</span>
            </button>

            <div class="ml-auto flex shrink-0 items-center gap-1.5">
                <Badge v-if="thread.entries.length > 1" variant="outline" class="px-1.5 text-[10px]">
                    {{ thread.entries.length - 1 }} follow-up{{ thread.entries.length === 2 ? '' : 's' }}
                </Badge>
                <Badge :variant="statusLabel.badge" class="px-1.5 text-[10px]">
                    <Loader2 v-if="thread.isActive" class="animate-spin" />
                    {{ statusLabel.label }}
                </Badge>
                <Button
                    v-if="showLocation && canShowInDiff"
                    variant="ghost"
                    size="sm"
                    class="h-7 px-2 text-xs [&_svg]:size-3.5"
                    @click="emit('show-in-diff')"
                >
                    <Crosshair />
                    Show in diff
                </Button>
                <template v-if="confirmingDelete">
                    <span class="text-xs text-muted-foreground">Delete thread?</span>
                    <Button variant="destructive" size="sm" class="h-7 px-2 text-xs" :disabled="deleting" @click="destroy">
                        <Loader2 v-if="deleting" class="animate-spin" />
                        Delete
                    </Button>
                    <Button variant="ghost" size="sm" class="h-7 px-2 text-xs" @click="confirmingDelete = false">Keep</Button>
                </template>
                <Button
                    v-else
                    variant="ghost"
                    size="icon"
                    class="size-7 text-muted-foreground hover:text-red-600 [&_svg]:size-3.5"
                    title="Delete this question thread"
                    @click="confirmingDelete = true"
                >
                    <Trash2 />
                    <span class="sr-only">Delete thread</span>
                </Button>
            </div>
        </div>

        <div v-if="open" class="divide-y">
            <div v-if="showLocation" class="px-4 py-3">
                <DiffHunk :hunk="root.selected_code" :visible-lines="12" />
            </div>
            <div v-for="question in thread.entries" :key="question.id" class="space-y-2.5 px-4 py-3">
                <div class="flex flex-wrap items-start gap-x-2 gap-y-1">
                    <span class="mt-0.5 rounded bg-muted px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide text-muted-foreground"
                        >You</span
                    >
                    <p class="min-w-0 flex-1 whitespace-pre-wrap text-sm font-medium">{{ question.prompt }}</p>
                    <Badge v-if="isOnEarlierCommit(question)" variant="warning" class="px-1.5 text-[10px]" title="The code may have changed since">
                        <GitCommitHorizontal />
                        asked on an earlier commit
                    </Badge>
                    <span class="text-xs text-muted-foreground" :title="formatDateTime(question.created_at)">{{ timeAgo(question.created_at) }}</span>
                </div>

                <div v-if="question.status === 'queued'" class="flex items-center gap-2 text-sm text-muted-foreground" role="status">
                    <Clock class="size-4 animate-pulse" />
                    Queued — waiting for Claude
                    <span class="text-xs">(<ElapsedTime :since="question.created_at" />)</span>
                </div>

                <div
                    v-else-if="question.status === 'running'"
                    class="flex items-center gap-2 text-sm text-violet-700 dark:text-violet-300"
                    role="status"
                >
                    <Loader2 class="size-4 animate-spin" />
                    Claude is thinking… (<ElapsedTime :since="question.started_at ?? question.created_at" />)
                </div>

                <div
                    v-else-if="question.status === 'failed'"
                    class="flex items-start gap-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"
                    role="alert"
                >
                    <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">Claude couldn't answer this.</p>
                        <p v-if="question.error" class="mt-0.5 whitespace-pre-wrap break-words font-mono text-xs">{{ question.error }}</p>
                    </div>
                    <Button
                        v-if="canAsk"
                        variant="outline"
                        size="sm"
                        class="h-7 shrink-0 border-red-300 bg-transparent px-2 text-xs hover:bg-red-100 dark:border-red-500/40 dark:hover:bg-red-500/20"
                        :disabled="retryingId !== null"
                        @click="retry(question)"
                    >
                        <Loader2 v-if="retryingId === question.id" class="animate-spin" />
                        <RotateCcw v-else />
                        Try again
                    </Button>
                </div>

                <template v-else>
                    <MarkdownText v-if="question.answer" :text="question.answer" />

                    <div v-if="question.suggested_code" class="overflow-hidden rounded-md border">
                        <div class="flex items-center justify-between gap-2 border-b bg-muted/50 px-3 py-1">
                            <span class="text-xs font-medium text-muted-foreground">Suggested code</span>
                            <Button variant="ghost" size="sm" class="h-6 px-2 text-xs [&_svg]:size-3.5" @click="copySuggestion(question)">
                                <Check v-if="copiedId === question.id" class="text-emerald-600" />
                                <Copy v-else />
                                {{ copiedId === question.id ? 'Copied' : 'Copy' }}
                            </Button>
                        </div>
                        <pre class="max-h-96 overflow-auto bg-muted/20 p-3 font-mono text-xs leading-5">{{ question.suggested_code }}</pre>
                    </div>

                    <p class="flex flex-wrap gap-x-3 text-[11px] text-muted-foreground">
                        <span v-if="question.model" class="font-mono">{{ question.model }}</span>
                        <span v-if="question.cost_usd != null" class="tabular-nums">{{ formatCost(question.cost_usd) }}</span>
                        <span v-if="question.duration_ms != null" class="tabular-nums">{{ formatDuration(question.duration_ms) }}</span>
                    </p>
                </template>
            </div>

            <div class="bg-muted/20 px-4 py-2.5">
                <AskClaudeComposer
                    v-if="followUpOpen"
                    v-model:prompt="followUpPrompt"
                    :pull-request-id="pullRequest.id"
                    :target="target"
                    :parent-id="root.id"
                    :can-ask="canAsk"
                    variant="follow-up"
                    @cancel="followUpOpen = false"
                    @submitted="followUpOpen = false"
                />
                <button
                    v-else
                    type="button"
                    :disabled="!canAsk"
                    class="flex h-8 w-full items-center gap-2 rounded-md border bg-background px-3 text-left text-sm text-muted-foreground transition-colors hover:border-foreground/30 disabled:cursor-not-allowed disabled:opacity-60"
                    :title="canAsk ? undefined : 'Connect Claude in Settings to ask follow-ups'"
                    @click="followUpOpen = true"
                >
                    <MessageSquarePlus class="size-4" />
                    Ask a follow-up…
                </button>
            </div>
        </div>
    </div>
</template>
