<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import type { ReplyDraft } from '@/types';
import { router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Loader2, RotateCcw, Send, Sparkles, X } from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';
import ElapsedTime from './ElapsedTime.vue';
import { failedWithFlash, pageVisit, refreshConversation, type DraftTarget } from './utils';

/**
 * Writes a reply to a review thread (with inReplyTo) or a comment on the pull request conversation,
 * optionally starting from a reply drafted by Claude.
 */
const props = withDefaults(
    defineProps<{
        pullRequestId: number;
        /** Database id of a review thread's first comment. Null posts a conversation comment. */
        inReplyTo?: number | null;
        draftTarget?: DraftTarget | null;
        /** Drafts for draftTarget, latest first. */
        drafts?: ReplyDraft[];
        canDraft?: boolean;
        collapsible?: boolean;
        placeholder?: string;
        submitLabel?: string;
    }>(),
    {
        inReplyTo: null,
        draftTarget: null,
        drafts: () => [],
        canDraft: false,
        collapsible: false,
        placeholder: 'Write a reply…',
        submitLabel: 'Reply',
    },
);

const MAX_CONTEXT_COMMENTS = 50;
const MAX_CONTEXT_BODY_LENGTH = 20000;

const form = useForm({ body: '' });
const container = ref<HTMLElement | null>(null);
const expanded = ref(!props.collapsible);
const showDraftOptions = ref(false);
const instructions = ref('');
const requestingDraft = ref(false);
const dismissedDraftId = ref<number | null>(null);

/** The discussion sent to Claude, trimmed to what the draft endpoint accepts. */
const draftContext = computed(() =>
    (props.draftTarget?.context ?? [])
        .filter((comment) => comment.body.trim() !== '')
        .slice(-MAX_CONTEXT_COMMENTS)
        .map((comment) => ({ author: comment.author, body: comment.body.slice(0, MAX_CONTEXT_BODY_LENGTH) })),
);

const draftingAvailable = computed(() => props.canDraft && props.draftTarget !== null && draftContext.value.length > 0);
const latestDraft = computed(() => props.drafts[0] ?? null);
const visibleDraft = computed(() => (latestDraft.value && latestDraft.value.id !== dismissedDraftId.value ? latestDraft.value : null));
const isDrafting = computed(() => requestingDraft.value || latestDraft.value?.is_active === true);

async function expand(): Promise<void> {
    expanded.value = true;
    await nextTick();
    container.value?.querySelector('textarea')?.focus();
}

function collapse(): void {
    if (props.collapsible && form.body.trim() === '') {
        expanded.value = false;
        showDraftOptions.value = false;
    }
}

function cancel(): void {
    form.reset();
    form.clearErrors();
    collapse();
}

function openDraftOptions(): void {
    showDraftOptions.value = true;
    expand();
}

function submit(): void {
    if (form.processing || form.body.trim() === '') {
        return;
    }

    form.transform((data) => ({
        body: data.body,
        ...(props.inReplyTo ? { in_reply_to: props.inReplyTo } : {}),
    })).post(
        route('pull-requests.comments.store', { pullRequest: props.pullRequestId }),
        pageVisit({
            onSuccess: (page) => {
                if (failedWithFlash(page)) {
                    return;
                }

                form.reset();
                collapse();
                refreshConversation();
            },
        }),
    );
}

function requestDraft(): void {
    const target = props.draftTarget;

    if (!target || isDrafting.value) {
        return;
    }

    router.post(
        route('pull-requests.reply-drafts.store', { pullRequest: props.pullRequestId }),
        {
            comment_node_id: target.nodeId,
            comment_database_id: target.databaseId,
            comment_type: target.type,
            instructions: instructions.value.trim() || null,
            conversation: draftContext.value,
            path: target.path ?? null,
            diff_hunk: target.diffHunk ? target.diffHunk.slice(-MAX_CONTEXT_BODY_LENGTH) : null,
        },
        pageVisit({
            onStart: () => (requestingDraft.value = true),
            onFinish: () => (requestingDraft.value = false),
            onSuccess: (page) => {
                if (!failedWithFlash(page)) {
                    showDraftOptions.value = false;
                    dismissedDraftId.value = null;
                }
            },
        }),
    );
}

function applyDraft(draft: ReplyDraft): void {
    form.body = draft.body ?? '';
    dismissedDraftId.value = draft.id;
    expand();
}

function handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        submit();
    }

    if (event.key === 'Escape' && props.collapsible && form.body.trim() === '') {
        event.preventDefault();
        collapse();
    }
}
</script>

<template>
    <div ref="container" class="space-y-2">
        <div
            v-if="visibleDraft && visibleDraft.is_active"
            class="flex items-center gap-2 rounded-md border border-violet-200 bg-violet-50 px-3 py-2 text-xs text-violet-900 dark:border-violet-500/30 dark:bg-violet-500/10 dark:text-violet-200"
            role="status"
        >
            <Loader2 class="size-3.5 animate-spin" />
            <span class="font-medium">Claude is drafting a reply…</span>
            <span class="opacity-75"><ElapsedTime :since="visibleDraft.created_at" /></span>
        </div>

        <div
            v-else-if="visibleDraft && visibleDraft.status === 'completed' && visibleDraft.body"
            class="overflow-hidden rounded-md border border-violet-200 dark:border-violet-500/30"
        >
            <div class="flex items-center gap-2 bg-violet-50 px-3 py-1.5 text-xs text-violet-900 dark:bg-violet-500/10 dark:text-violet-200">
                <Sparkles class="size-3.5" />
                <span class="font-medium">Claude's draft</span>
                <span v-if="visibleDraft.instructions" class="truncate opacity-75">— “{{ visibleDraft.instructions }}”</span>
                <button
                    type="button"
                    class="ml-auto rounded p-0.5 opacity-75 hover:opacity-100"
                    title="Dismiss draft"
                    @click="dismissedDraftId = visibleDraft.id"
                >
                    <X class="size-3.5" />
                    <span class="sr-only">Dismiss draft</span>
                </button>
            </div>
            <p class="max-h-48 overflow-y-auto whitespace-pre-wrap px-3 py-2 text-sm">{{ visibleDraft.body }}</p>
            <div class="flex items-center justify-end gap-2 border-t px-3 py-1.5">
                <Button type="button" variant="ghost" size="sm" class="h-7 text-xs" :disabled="isDrafting" @click="openDraftOptions">
                    <RotateCcw class="size-3.5" />
                    Redraft
                </Button>
                <Button type="button" size="sm" class="h-7 text-xs" @click="applyDraft(visibleDraft)">Use draft</Button>
            </div>
        </div>

        <div
            v-else-if="visibleDraft && visibleDraft.status === 'failed'"
            class="flex items-start gap-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"
            role="alert"
        >
            <AlertTriangle class="mt-px size-3.5 shrink-0" />
            <div class="min-w-0 flex-1">
                <p class="font-medium">Claude couldn't draft a reply.</p>
                <p v-if="visibleDraft.error" class="mt-0.5 whitespace-pre-wrap break-words font-mono">{{ visibleDraft.error }}</p>
            </div>
            <Button type="button" variant="ghost" size="sm" class="h-6 px-2 text-xs" :disabled="isDrafting" @click="requestDraft">Try again</Button>
            <button type="button" class="rounded p-0.5 opacity-75 hover:opacity-100" title="Dismiss" @click="dismissedDraftId = visibleDraft.id">
                <X class="size-3.5" />
                <span class="sr-only">Dismiss</span>
            </button>
        </div>

        <div v-if="!expanded" class="flex items-center gap-2">
            <button
                type="button"
                class="h-8 flex-1 rounded-md border bg-background px-3 text-left text-sm text-muted-foreground transition-colors hover:border-foreground/30"
                @click="expand"
            >
                {{ placeholder }}
            </button>
            <Button v-if="draftingAvailable" type="button" variant="outline" size="sm" :disabled="isDrafting" @click="openDraftOptions">
                <Sparkles />
                Draft with Claude
            </Button>
        </div>

        <form v-else class="space-y-2" @submit.prevent="submit">
            <Textarea v-model="form.body" :placeholder="placeholder" class="min-h-24 text-sm" @keydown="handleKeydown" />
            <InputError :message="form.errors.body" />

            <div
                v-if="showDraftOptions && draftingAvailable"
                class="flex flex-col gap-2 rounded-md border border-dashed p-2 sm:flex-row sm:items-center"
            >
                <Input
                    :model-value="instructions"
                    placeholder="Optional: tell Claude what to say, e.g. “agree, I'll fix it in a follow-up”"
                    class="h-8 text-xs"
                    @update:model-value="instructions = String($event)"
                    @keydown.enter.prevent="requestDraft"
                />
                <div class="flex shrink-0 items-center gap-1">
                    <Button type="button" size="sm" class="h-8" :disabled="isDrafting" @click="requestDraft">
                        <Loader2 v-if="isDrafting" class="animate-spin" />
                        <Sparkles v-else />
                        Draft reply
                    </Button>
                    <Button type="button" variant="ghost" size="sm" class="h-8" @click="showDraftOptions = false">Cancel</Button>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Button
                    v-if="draftingAvailable && !showDraftOptions"
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="h-8 text-muted-foreground"
                    :disabled="isDrafting"
                    @click="showDraftOptions = true"
                >
                    <Sparkles />
                    Draft with Claude
                </Button>
                <span class="hidden text-xs text-muted-foreground sm:inline">⌘/Ctrl + Enter to send</span>
                <div class="ml-auto flex items-center gap-2">
                    <Button v-if="collapsible" type="button" variant="ghost" size="sm" class="h-8" @click="cancel">Cancel</Button>
                    <Button type="submit" size="sm" class="h-8" :disabled="form.processing || form.body.trim() === ''">
                        <Loader2 v-if="form.processing" class="animate-spin" />
                        <Send v-else />
                        {{ submitLabel }}
                    </Button>
                </div>
            </div>
        </form>
    </div>
</template>
