<script setup lang="ts">
import ExternalLink from '@/components/ExternalLink.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDuration, timeAgo } from '@/lib/pull-requests';
import type { CodeChange, PullRequestPermissions } from '@/types';
import { router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    ExternalLink as ExternalLinkIcon,
    GitBranch,
    GitCommitHorizontal,
    GitPullRequestArrow,
    Loader2,
    RotateCcw,
    Trash2,
    Upload,
    Wand2,
    XCircle,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import CodeChangeDiff from './CodeChangeDiff.vue';
import ElapsedTime from './ElapsedTime.vue';
import MarkdownText from './MarkdownText.vue';
import SettingsHint from './SettingsHint.vue';
import { codeChangeStatusStyles, formatCost, formatDateTime, pageVisit, shortSha } from './utils';

/**
 * One "Fix with Claude" attempt: progress while Claude works, then the summary, commits and diff to review,
 * with Push, Discard and Try again.
 */
const props = defineProps<{
    change: CodeChange;
    headRef: string | null;
    permissions: PullRequestPermissions;
}>();

const emit = defineEmits<{
    retry: [change: CodeChange];
}>();

const pushing = ref(false);
const discarding = ref(false);
const confirmingDiscard = ref(false);

const isNewPullRequest = computed(() => props.change.target === 'new_pull_request');
const status = computed(() => codeChangeStatusStyles[props.change.status]);
const pushLabel = computed(() => (isNewPullRequest.value ? 'Push and open pull request' : `Push to ${props.change.destination_branch}`));

function push(): void {
    router.post(
        route('code-changes.push', { codeChange: props.change.id }),
        {},
        pageVisit({
            onStart: () => (pushing.value = true),
            onFinish: () => (pushing.value = false),
        }),
    );
}

function discard(): void {
    router.delete(
        route('code-changes.destroy', { codeChange: props.change.id }),
        pageVisit({
            onStart: () => (discarding.value = true),
            onFinish: () => {
                discarding.value = false;
                confirmingDiscard.value = false;
            },
        }),
    );
}
</script>

<template>
    <article class="overflow-clip rounded-lg border bg-card text-card-foreground">
        <header class="flex flex-wrap items-center gap-2 border-b px-4 py-2.5">
            <Wand2 class="size-4 text-violet-500" />
            <h3 class="text-sm font-medium">Fix with Claude</h3>
            <Badge :variant="status.badge">
                <Loader2 v-if="change.is_active" class="animate-spin" />
                {{ status.label }}
            </Badge>
            <span class="inline-flex items-center gap-1 text-xs text-muted-foreground">
                <component :is="isNewPullRequest ? GitPullRequestArrow : GitBranch" class="size-3.5" />
                <template v-if="isNewPullRequest">
                    new pull request into <code class="font-mono text-foreground">{{ headRef }}</code>
                </template>
                <template v-else>
                    commits on <code class="font-mono text-foreground">{{ change.destination_branch }}</code>
                </template>
            </span>
            <span class="ml-auto text-xs text-muted-foreground" :title="formatDateTime(change.created_at)">{{ timeAgo(change.created_at) }}</span>
        </header>

        <div class="space-y-4 px-4 py-3">
            <div v-if="change.instructions || change.finding_ids.length" class="space-y-1 text-sm">
                <p v-if="change.instructions" class="whitespace-pre-wrap border-l-2 pl-3 text-muted-foreground">{{ change.instructions }}</p>
                <p v-if="change.finding_ids.length" class="text-xs text-muted-foreground">
                    Fixing {{ change.finding_ids.length }} AI finding{{ change.finding_ids.length === 1 ? '' : 's' }}
                </p>
            </div>

            <div
                v-if="change.status === 'queued' || change.status === 'running'"
                class="flex items-center gap-3 rounded-lg border border-violet-300 bg-violet-50 px-4 py-3 text-sm text-violet-950 dark:border-violet-500/30 dark:bg-violet-500/10 dark:text-violet-100"
                role="status"
                aria-live="polite"
            >
                <Loader2 class="size-4 shrink-0 animate-spin text-violet-500" />
                <span class="font-medium">Claude is changing the code… (<ElapsedTime :since="change.started_at ?? change.created_at" />)</span>
                <span class="ml-auto hidden text-xs opacity-75 sm:inline">
                    {{
                        change.status === 'queued'
                            ? 'Waiting for the worker to pick it up.'
                            : 'Working in a separate worktree. Nothing is pushed yet.'
                    }}
                </span>
            </div>

            <div
                v-else-if="change.status === 'pushing'"
                class="flex items-center gap-3 rounded-lg border border-sky-300 bg-sky-50 px-4 py-3 text-sm text-sky-950 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100"
                role="status"
                aria-live="polite"
            >
                <Loader2 class="size-4 shrink-0 animate-spin text-sky-500" />
                <span class="font-medium">
                    Pushing to <code class="font-mono">{{ change.destination_branch }}</code
                    >{{ isNewPullRequest ? ' and opening a pull request' : '' }}…
                </span>
            </div>

            <div
                v-else-if="change.status === 'pushed'"
                class="flex flex-wrap items-center gap-3 rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-950 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100"
                role="status"
            >
                <CheckCircle2 class="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                <span class="font-medium">
                    Pushed to <code class="font-mono">{{ change.pushed_branch ?? change.destination_branch }}</code>
                    <span v-if="change.pushed_at" class="font-normal opacity-75" :title="formatDateTime(change.pushed_at)">
                        {{ timeAgo(change.pushed_at) }}
                    </span>
                </span>
                <Button v-if="change.created_pull_request_url" size="sm" variant="outline" class="ml-auto h-7 bg-transparent text-xs" as-child>
                    <ExternalLink :href="change.created_pull_request_url">
                        <ExternalLinkIcon />
                        Open pull request
                    </ExternalLink>
                </Button>
            </div>

            <div
                v-else-if="change.status === 'failed'"
                class="flex items-start gap-3 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"
                role="alert"
            >
                <XCircle class="mt-0.5 size-4 shrink-0" />
                <div class="min-w-0 flex-1 space-y-1">
                    <p class="font-medium">Claude couldn't make the changes</p>
                    <p v-if="change.error" class="max-h-40 overflow-auto whitespace-pre-wrap break-words font-mono text-xs">{{ change.error }}</p>
                </div>
            </div>

            <p v-else-if="change.status === 'no_changes'" class="flex items-center gap-2 text-sm font-medium">
                <AlertTriangle class="size-4 text-amber-500" />
                Claude didn't change anything.
            </p>

            <div
                v-if="change.status === 'ready' && change.error"
                class="flex items-start gap-3 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"
                role="alert"
            >
                <XCircle class="mt-0.5 size-4 shrink-0" />
                <div class="min-w-0 flex-1 space-y-1">
                    <p class="font-medium">The last push didn't go through. The changes are still here, so you can push again.</p>
                    <p class="max-h-40 overflow-auto whitespace-pre-wrap break-words font-mono text-xs">{{ change.error }}</p>
                </div>
            </div>

            <MarkdownText v-if="change.summary && !change.is_active" :text="change.summary" />

            <div
                v-if="change.commits.length && (change.status === 'ready' || change.status === 'pushed' || change.status === 'pushing')"
                class="space-y-1"
            >
                <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                    {{ change.commits.length }} commit{{ change.commits.length === 1 ? '' : 's' }}
                </p>
                <ul class="divide-y rounded-md border text-sm">
                    <li v-for="commit in change.commits" :key="commit.sha" class="flex items-start gap-2 px-3 py-1.5">
                        <GitCommitHorizontal class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                        <code class="mt-px shrink-0 font-mono text-xs text-muted-foreground">{{ shortSha(commit.sha) }}</code>
                        <span class="min-w-0 break-words">{{ commit.message }}</span>
                    </li>
                </ul>
            </div>

            <div v-if="!change.is_active && change.status !== 'failed'" class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                <span v-if="change.status !== 'no_changes'" class="font-mono tabular-nums">
                    <span class="text-emerald-600 dark:text-emerald-400">+{{ change.additions }}</span>
                    <span class="ml-1 text-red-600 dark:text-red-400">−{{ change.deletions }}</span>
                </span>
                <span v-if="change.status !== 'no_changes'">{{ change.files_changed }} file{{ change.files_changed === 1 ? '' : 's' }} changed</span>
                <span v-if="change.cost_usd != null" class="tabular-nums">{{ formatCost(change.cost_usd) }}</span>
                <span v-if="change.duration_ms != null" class="tabular-nums">{{ formatDuration(change.duration_ms) }}</span>
                <span v-if="change.model" class="font-mono">{{ change.model }}</span>
            </div>

            <div v-if="!change.is_active && change.status !== 'pushed'" class="flex flex-wrap items-center gap-2 border-t pt-3">
                <template v-if="change.status === 'ready'">
                    <Button
                        size="sm"
                        :disabled="!permissions.can_push || pushing"
                        class="bg-emerald-600 text-white hover:bg-emerald-700 dark:bg-emerald-600 dark:text-white dark:hover:bg-emerald-500"
                        @click="push"
                    >
                        <Loader2 v-if="pushing" class="animate-spin" />
                        <Upload v-else />
                        {{ pushLabel }}
                    </Button>
                    <SettingsHint v-if="!permissions.can_push" message="Pushing is off" />
                </template>

                <Button size="sm" variant="outline" @click="emit('retry', change)">
                    <RotateCcw />
                    Try again
                </Button>

                <div class="ml-auto flex items-center gap-2">
                    <template v-if="confirmingDiscard">
                        <span class="text-xs text-muted-foreground">
                            {{
                                change.status === 'ready'
                                    ? 'Discard these changes? This removes the worktree and its commits.'
                                    : 'Remove this attempt?'
                            }}
                        </span>
                        <Button size="sm" variant="destructive" :disabled="discarding" @click="discard">
                            <Loader2 v-if="discarding" class="animate-spin" />
                            {{ change.status === 'ready' ? 'Discard' : 'Remove' }}
                        </Button>
                        <Button size="sm" variant="ghost" @click="confirmingDiscard = false">Keep</Button>
                    </template>
                    <Button v-else size="sm" variant="ghost" class="text-muted-foreground hover:text-red-600" @click="confirmingDiscard = true">
                        <Trash2 />
                        {{ change.status === 'ready' ? 'Discard' : 'Remove' }}
                    </Button>
                </div>
            </div>

            <CodeChangeDiff v-if="change.status === 'ready' && change.diff" :diff="change.diff" />
        </div>
    </article>
</template>
