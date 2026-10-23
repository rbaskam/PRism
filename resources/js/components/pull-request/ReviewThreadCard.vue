<script setup lang="ts">
import ExternalLink from '@/components/ExternalLink.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import type { PullRequestPermissions, ReplyDraft, ReviewThread } from '@/types';
import { router } from '@inertiajs/vue3';
import { CheckCircle2, ChevronRight, ExternalLink as ExternalLinkIcon, Loader2, MessageSquare } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import CommentItem from './CommentItem.vue';
import DiffHunk from './DiffHunk.vue';
import ReplyComposer from './ReplyComposer.vue';
import SettingsHint from './SettingsHint.vue';
import { failedWithFlash, pageVisit, refreshConversation, type DraftTarget } from './utils';

/**
 * A review thread on a line of the diff: its context, comments, reply box and resolve action.
 * Resolved threads start collapsed.
 */
const props = withDefaults(
    defineProps<{
        thread: ReviewThread;
        pullRequestId: number;
        permissions: PullRequestPermissions;
        replyDrafts: ReplyDraft[];
        /** False when the card is already shown under its line in the diff. */
        showDiffHunk?: boolean;
    }>(),
    {
        showDiffHunk: true,
    },
);

const open = ref(!props.thread.is_resolved);
const resolving = ref(false);

watch(
    () => props.thread.is_resolved,
    (isResolved) => {
        if (isResolved) {
            open.value = false;
        }
    },
);

const firstComment = computed(() => props.thread.comments[0] ?? null);
const lastComment = computed(() => props.thread.comments[props.thread.comments.length - 1] ?? null);

const draftTarget = computed<DraftTarget | null>(() =>
    lastComment.value
        ? {
              nodeId: lastComment.value.node_id,
              databaseId: lastComment.value.database_id,
              type: 'review_thread',
              context: props.thread.comments.map((comment) => ({ author: comment.author_login, body: comment.body })),
              path: props.thread.path,
              diffHunk: props.thread.diff_hunk,
          }
        : null,
);

const drafts = computed(() => (lastComment.value ? props.replyDrafts.filter((draft) => draft.comment_node_id === lastComment.value?.node_id) : []));

const excerpt = computed(() => firstComment.value?.body.replace(/\s+/g, ' ').trim().slice(0, 140) ?? '');

function resolve(): void {
    router.post(
        route('pull-requests.threads.resolve', { pullRequest: props.pullRequestId }),
        { thread_id: props.thread.id },
        pageVisit({
            onStart: () => (resolving.value = true),
            onFinish: () => (resolving.value = false),
            onSuccess: (page) => {
                if (!failedWithFlash(page)) {
                    refreshConversation();
                }
            },
        }),
    );
}
</script>

<template>
    <Collapsible
        v-model:open="open"
        class="overflow-hidden rounded-lg border bg-card text-card-foreground"
        :class="{ 'opacity-80': thread.is_resolved && !open }"
    >
        <div class="flex items-center gap-2 px-3 py-2" :class="{ 'border-b': open }">
            <CollapsibleTrigger as-child>
                <button type="button" class="flex min-w-0 flex-1 items-center gap-2 text-left">
                    <ChevronRight class="size-4 shrink-0 text-muted-foreground transition-transform" :class="{ 'rotate-90': open }" />
                    <span class="shrink-0 font-mono text-xs text-muted-foreground">{{ thread.line ? `Line ${thread.line}` : 'File' }}</span>
                    <span v-if="!open && firstComment" class="min-w-0 truncate text-xs">
                        <span class="font-medium">{{ firstComment.author_login }}:</span>
                        <span class="text-muted-foreground"> {{ excerpt }}</span>
                    </span>
                </button>
            </CollapsibleTrigger>

            <div class="flex shrink-0 items-center gap-1.5">
                <Badge v-if="thread.is_outdated" variant="warning" class="px-1.5 text-[10px]">Outdated</Badge>
                <Badge v-if="thread.is_resolved" variant="success" class="px-1.5 text-[10px]">
                    <CheckCircle2 />
                    Resolved
                </Badge>
                <span class="inline-flex items-center gap-1 text-xs text-muted-foreground" :title="`${thread.comments.length} comments`">
                    <MessageSquare class="size-3.5" />
                    {{ thread.comments.length }}
                </span>
                <Button
                    v-if="!thread.is_resolved && permissions.can_reply_to_comments"
                    variant="ghost"
                    size="sm"
                    class="h-7 px-2 text-xs"
                    :disabled="resolving"
                    @click="resolve"
                >
                    <Loader2 v-if="resolving" class="animate-spin" />
                    <CheckCircle2 v-else />
                    Resolve
                </Button>
                <ExternalLink
                    v-if="firstComment?.url"
                    :href="firstComment.url"
                    class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                    title="Open on GitHub"
                >
                    <ExternalLinkIcon class="size-3.5" />
                    <span class="sr-only">Open on GitHub</span>
                </ExternalLink>
            </div>
        </div>

        <CollapsibleContent>
            <div class="space-y-4 p-3">
                <DiffHunk v-if="showDiffHunk && thread.diff_hunk" :hunk="thread.diff_hunk" />

                <div class="space-y-4">
                    <CommentItem v-for="comment in thread.comments" :key="comment.node_id" :comment="comment" />
                </div>

                <div class="border-t pt-3">
                    <ReplyComposer
                        v-if="permissions.can_reply_to_comments && firstComment?.database_id"
                        :pull-request-id="pullRequestId"
                        :in-reply-to="firstComment.database_id"
                        :draft-target="draftTarget"
                        :drafts="drafts"
                        :can-draft="permissions.can_run_review"
                        collapsible
                    />
                    <SettingsHint v-else-if="!permissions.can_reply_to_comments" message="Replying is off" />
                </div>
            </div>
        </CollapsibleContent>
    </Collapsible>
</template>
