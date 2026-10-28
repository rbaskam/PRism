<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { PullRequest, PullRequestConversation, PullRequestPermissions, ReviewFinding } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { GitBranch, GitPullRequestArrow, Loader2, ShieldCheck, Wand2 } from 'lucide-vue-next';
import { computed, watch } from 'vue';
import SeverityBadge from './SeverityBadge.vue';
import { failedWithFlash, findingLocation, pageVisit, type CodeChangeDraft } from './utils';

type Target = 'pull_request_branch' | 'new_pull_request';

/** The backend accepts at most this many review comments, each up to this long. */
const MAX_COMMENTS = 50;
const MAX_COMMENT_LENGTH = 20000;

/**
 * "Fix with Claude": choose what Claude should change (instructions, findings, review threads) and where the
 * result should go. Claude works in a separate worktree and nothing is pushed until the user approves the diff.
 */
const props = defineProps<{
    pullRequest: PullRequest;
    findings: ReviewFinding[];
    /** Undefined while the conversation is still loading. */
    conversation: PullRequestConversation | undefined;
    permissions: PullRequestPermissions;
    draft: CodeChangeDraft;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    started: [];
}>();

const defaultTarget = computed<Target>(() => (props.pullRequest.is_authored_by_me ? 'pull_request_branch' : 'new_pull_request'));

const form = useForm<{ target: Target; instructions: string; finding_ids: number[]; thread_ids: string[] }>({
    target: defaultTarget.value,
    instructions: '',
    finding_ids: [],
    thread_ids: [],
});

/** Findings worth fixing: still present and not dismissed as not useful. */
const openFindings = computed(() => props.findings.filter((finding) => !finding.is_resolved && finding.status !== 'not_useful'));
const unresolvedThreads = computed(() => props.conversation?.threads.filter((thread) => !thread.is_resolved) ?? []);

const selectedComments = computed(() =>
    unresolvedThreads.value
        .filter((thread) => form.thread_ids.includes(thread.id))
        .flatMap((thread) =>
            thread.comments
                .filter((comment) => comment.body.trim() !== '')
                .map((comment) => ({
                    author: comment.author_login,
                    body: comment.body.slice(0, MAX_COMMENT_LENGTH),
                    path: thread.path,
                    line: thread.line,
                })),
        ),
);

const isInstructionsRequired = computed(() => form.finding_ids.length === 0 && selectedComments.value.length === 0);
const canSubmit = computed(() => !form.processing && (!isInstructionsRequired.value || form.instructions.trim() !== ''));
const otherError = computed(() => Object.entries(form.errors).find(([field]) => field !== 'instructions')?.[1]);

/** Start from the draft when it has content (a finding, a retry); otherwise keep what was typed last time. */
watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    const hasDraft = Boolean(props.draft.instructions) || (props.draft.findingIds?.length ?? 0) > 0;

    if (hasDraft) {
        form.instructions = props.draft.instructions ?? '';
        form.finding_ids = (props.draft.findingIds ?? []).filter((id) => openFindings.value.some((finding) => finding.id === id));
        form.thread_ids = [];
    }

    form.target = props.draft.target ?? (hasDraft ? defaultTarget.value : form.target);
    form.thread_ids = form.thread_ids.filter((id) => unresolvedThreads.value.some((thread) => thread.id === id));
    form.clearErrors();
});

function toggleFinding(id: number, checked: boolean): void {
    form.finding_ids = checked ? [...form.finding_ids, id] : form.finding_ids.filter((selectedId) => selectedId !== id);
}

function toggleThread(id: string, checked: boolean): void {
    form.thread_ids = checked ? [...form.thread_ids, id] : form.thread_ids.filter((selectedId) => selectedId !== id);
}

function threadExcerpt(body: string): string {
    return body.replace(/\s+/g, ' ').trim().slice(0, 120);
}

function submit(): void {
    if (!canSubmit.value) {
        return;
    }

    form.transform((data) => ({
        target: data.target,
        instructions: data.instructions.trim() === '' ? null : data.instructions,
        ...(data.finding_ids.length > 0 ? { finding_ids: data.finding_ids } : {}),
        ...(selectedComments.value.length > 0 ? { comments: selectedComments.value.slice(0, MAX_COMMENTS) } : {}),
    })).post(
        route('pull-requests.code-changes.store', { pullRequest: props.pullRequest.id }),
        pageVisit({
            onSuccess: (page) => {
                if (failedWithFlash(page)) {
                    return;
                }

                form.reset();
                form.target = defaultTarget.value;
                open.value = false;
                emit('started');
            },
        }),
    );
}

function handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        submit();
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] max-w-2xl overflow-y-auto">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <Wand2 class="size-5 text-violet-500" />
                    Fix with Claude
                </DialogTitle>
                <DialogDescription>Tell Claude what to change. You'll review the diff before anything leaves your computer.</DialogDescription>
            </DialogHeader>

            <form class="grid gap-5" @submit.prevent="submit">
                <div class="grid gap-1.5">
                    <Label for="fix-instructions">
                        Instructions
                        <span class="font-normal text-muted-foreground">{{ isInstructionsRequired ? '(required)' : '(optional)' }}</span>
                    </Label>
                    <Textarea
                        id="fix-instructions"
                        v-model="form.instructions"
                        placeholder="e.g. Rename the helper and add a test"
                        class="min-h-24"
                        @keydown="handleKeydown"
                    />
                    <InputError :message="form.errors.instructions" />
                </div>

                <div v-if="openFindings.length > 0" class="grid gap-2">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm font-medium">
                            AI findings to fix
                            <span class="font-normal text-muted-foreground">({{ form.finding_ids.length }} of {{ openFindings.length }})</span>
                        </p>
                        <div class="flex items-center gap-2 text-xs">
                            <button
                                type="button"
                                class="text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                                @click="form.finding_ids = openFindings.map((finding) => finding.id)"
                            >
                                Select all
                            </button>
                            <button
                                type="button"
                                class="text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                                @click="form.finding_ids = []"
                            >
                                None
                            </button>
                        </div>
                    </div>
                    <ul class="max-h-52 divide-y overflow-y-auto rounded-md border">
                        <li v-for="finding in openFindings" :key="finding.id">
                            <label class="flex cursor-pointer items-start gap-2.5 px-3 py-2 hover:bg-muted/50">
                                <Checkbox
                                    class="mt-0.5 size-4"
                                    :checked="form.finding_ids.includes(finding.id)"
                                    @update:checked="(checked: boolean) => toggleFinding(finding.id, checked)"
                                />
                                <span class="min-w-0 flex-1 space-y-0.5">
                                    <span class="flex items-start gap-2">
                                        <SeverityBadge :severity="finding.severity" />
                                        <span class="text-sm leading-5">{{ finding.title }}</span>
                                    </span>
                                    <span v-if="findingLocation(finding)" class="block truncate font-mono text-xs text-muted-foreground">
                                        {{ findingLocation(finding) }}
                                    </span>
                                </span>
                            </label>
                        </li>
                    </ul>
                </div>

                <div class="grid gap-2">
                    <p class="text-sm font-medium">
                        Review threads to address
                        <span v-if="conversation" class="font-normal text-muted-foreground">
                            ({{ form.thread_ids.length }} of {{ unresolvedThreads.length }})
                        </span>
                    </p>
                    <p v-if="!conversation" class="flex items-center gap-2 text-xs text-muted-foreground">
                        <Loader2 class="size-3.5 animate-spin" />
                        Loading the conversation from GitHub…
                    </p>
                    <p v-else-if="unresolvedThreads.length === 0" class="text-xs text-muted-foreground">There are no unresolved review threads.</p>
                    <ul v-else class="max-h-52 divide-y overflow-y-auto rounded-md border">
                        <li v-for="thread in unresolvedThreads" :key="thread.id">
                            <label class="flex cursor-pointer items-start gap-2.5 px-3 py-2 hover:bg-muted/50">
                                <Checkbox
                                    class="mt-0.5 size-4"
                                    :checked="form.thread_ids.includes(thread.id)"
                                    @update:checked="(checked: boolean) => toggleThread(thread.id, checked)"
                                />
                                <span class="min-w-0 flex-1 space-y-0.5">
                                    <span class="block truncate font-mono text-xs text-muted-foreground">
                                        {{ thread.path }}{{ thread.line ? `:${thread.line}` : '' }}
                                    </span>
                                    <span v-if="thread.comments[0]" class="block text-sm leading-5">
                                        <span class="font-medium">{{ thread.comments[0].author_login }}:</span>
                                        {{ threadExcerpt(thread.comments[0].body) }}
                                    </span>
                                    <span v-if="thread.comments.length > 1" class="block text-xs text-muted-foreground">
                                        +{{ thread.comments.length - 1 }} more comment{{ thread.comments.length === 2 ? '' : 's' }}
                                    </span>
                                </span>
                            </label>
                        </li>
                    </ul>
                    <p v-if="selectedComments.length > MAX_COMMENTS" class="text-xs text-amber-700 dark:text-amber-400">
                        Only the first {{ MAX_COMMENTS }} comments are sent to Claude.
                    </p>
                </div>

                <div class="grid gap-2">
                    <p class="text-sm font-medium">Where should the changes go?</p>
                    <div role="radiogroup" aria-label="Where the changes go" class="grid gap-2 sm:grid-cols-2">
                        <button
                            type="button"
                            role="radio"
                            :aria-checked="form.target === 'pull_request_branch'"
                            :class="
                                cn(
                                    'flex flex-col items-start gap-1 rounded-lg border p-3 text-left transition-colors hover:bg-muted/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                    form.target === 'pull_request_branch' && 'border-primary bg-primary/5 ring-1 ring-primary',
                                )
                            "
                            @click="form.target = 'pull_request_branch'"
                        >
                            <span class="flex items-center gap-1.5 text-sm font-medium">
                                <GitBranch class="size-4 text-violet-500" />
                                Commit to this pull request's branch
                            </span>
                            <span class="text-xs text-muted-foreground">
                                Adds commits to <code class="rounded bg-muted px-1 font-mono">{{ pullRequest.head_ref }}</code
                                >.
                            </span>
                        </button>
                        <button
                            type="button"
                            role="radio"
                            :aria-checked="form.target === 'new_pull_request'"
                            :class="
                                cn(
                                    'flex flex-col items-start gap-1 rounded-lg border p-3 text-left transition-colors hover:bg-muted/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                    form.target === 'new_pull_request' && 'border-primary bg-primary/5 ring-1 ring-primary',
                                )
                            "
                            @click="form.target = 'new_pull_request'"
                        >
                            <span class="flex items-center gap-1.5 text-sm font-medium">
                                <GitPullRequestArrow class="size-4 text-violet-500" />
                                Open a new pull request
                            </span>
                            <span class="text-xs text-muted-foreground">
                                Into <code class="rounded bg-muted px-1 font-mono">{{ pullRequest.head_ref }}</code
                                >, so the author can review it.
                            </span>
                        </button>
                    </div>
                    <InputError :message="form.errors.target" />
                </div>

                <p class="flex items-start gap-2 rounded-md bg-muted/60 px-3 py-2 text-xs text-muted-foreground">
                    <ShieldCheck class="mt-px size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                    <span>
                        Nothing is pushed until you've reviewed and approved the diff. Claude works in a separate git worktree of your local checkout,
                        so your own checkout and branch are untouched.
                    </span>
                </p>

                <InputError :message="otherError" />

                <DialogFooter class="gap-2">
                    <Button type="button" variant="outline" @click="open = false">Cancel</Button>
                    <Button type="submit" :disabled="!canSubmit">
                        <Loader2 v-if="form.processing" class="animate-spin" />
                        <Wand2 v-else />
                        Start fixing
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
