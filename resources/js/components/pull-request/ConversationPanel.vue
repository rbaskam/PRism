<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { PullRequest, PullRequestConversation, PullRequestPermissions, ReplyDraft, ReviewThread } from '@/types';
import { router } from '@inertiajs/vue3';
import { AlertTriangle, FileCode2, Loader2, MessagesSquare, RotateCcw } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import CommentItem from './CommentItem.vue';
import ReplyComposer from './ReplyComposer.vue';
import ReviewThreadCard from './ReviewThreadCard.vue';
import SettingsHint from './SettingsHint.vue';
import { reviewStateStyles, type DraftTarget } from './utils';

/**
 * The pull request's conversation on GitHub: submitted reviews, review threads grouped by file,
 * and the general comments with a box to add one.
 */
const props = defineProps<{
    conversation: PullRequestConversation;
    pullRequest: PullRequest;
    permissions: PullRequestPermissions;
    replyDrafts: ReplyDraft[];
}>();

const retrying = ref(false);

interface ThreadGroup {
    path: string;
    threads: ReviewThread[];
    unresolvedCount: number;
}

/** Threads grouped by file; files with open threads first. */
const threadGroups = computed<ThreadGroup[]>(() => {
    const groups = new Map<string, ReviewThread[]>();

    for (const thread of props.conversation.threads) {
        groups.set(thread.path, [...(groups.get(thread.path) ?? []), thread]);
    }

    return Array.from(groups, ([path, threads]) => ({
        path,
        threads: [...threads].sort((a, b) => Number(a.is_resolved) - Number(b.is_resolved) || (a.line ?? 0) - (b.line ?? 0)),
        unresolvedCount: threads.filter((thread) => !thread.is_resolved).length,
    })).sort((a, b) => Number(a.unresolvedCount === 0) - Number(b.unresolvedCount === 0) || a.path.localeCompare(b.path));
});

const unresolvedThreadCount = computed(() => props.conversation.threads.filter((thread) => !thread.is_resolved).length);

const isEmpty = computed(
    () => props.conversation.threads.length === 0 && props.conversation.comments.length === 0 && props.conversation.reviews.length === 0,
);

const lastComment = computed(() => props.conversation.comments[props.conversation.comments.length - 1] ?? null);

/** A draft for the general conversation is linked to its last comment; reviews and comments are its context. */
const conversationDraftTarget = computed<DraftTarget | null>(() => {
    if (!lastComment.value) {
        return null;
    }

    const context = [...props.conversation.reviews, ...props.conversation.comments]
        .sort((a, b) => new Date(a.created_at ?? 0).getTime() - new Date(b.created_at ?? 0).getTime())
        .map((comment) => ({ author: comment.author_login, body: comment.body }));

    return {
        nodeId: lastComment.value.node_id,
        databaseId: lastComment.value.database_id,
        type: 'conversation',
        context,
    };
});

const conversationDrafts = computed(() =>
    lastComment.value ? props.replyDrafts.filter((draft) => draft.comment_node_id === lastComment.value?.node_id) : [],
);

function retry(): void {
    router.reload({
        only: ['conversation'],
        onStart: () => (retrying.value = true),
        onFinish: () => (retrying.value = false),
    });
}
</script>

<template>
    <div class="space-y-8">
        <!-- The conversation is saved between visits; this fetches it from GitHub straight away. -->
        <div v-if="!conversation.error" class="-mb-6 flex justify-end">
            <Button variant="ghost" size="sm" class="text-muted-foreground" :disabled="retrying" @click="retry">
                <Loader2 v-if="retrying" class="animate-spin" />
                <RotateCcw v-else />
                Refresh from GitHub
            </Button>
        </div>

        <div
            v-if="conversation.error"
            class="flex items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
            role="alert"
        >
            <AlertTriangle class="mt-0.5 size-4 shrink-0" />
            <div class="min-w-0 flex-1">
                <p class="font-medium">Couldn't load the conversation from GitHub</p>
                <p class="mt-0.5 break-words text-xs opacity-90">{{ conversation.error }}</p>
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

        <div v-else-if="isEmpty" class="rounded-lg border border-dashed px-6 py-10 text-center">
            <MessagesSquare class="mx-auto size-8 text-muted-foreground/60" />
            <p class="mt-2 text-sm font-medium">No reviews or comments yet</p>
            <p class="text-sm text-muted-foreground">Start the conversation below, or submit a review from the header.</p>
        </div>

        <section v-if="conversation.reviews.length" class="space-y-3">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Reviews · {{ conversation.reviews.length }}</h2>
            <div class="divide-y rounded-lg border bg-card">
                <div v-for="review in conversation.reviews" :key="review.node_id" class="px-4 py-3">
                    <CommentItem :comment="review">
                        <template #badge>
                            <Badge :variant="reviewStateStyles[review.state].badge" class="px-1.5 py-0 text-[10px]">
                                {{ reviewStateStyles[review.state].label }}
                            </Badge>
                        </template>
                    </CommentItem>
                </div>
            </div>
        </section>

        <section v-if="threadGroups.length" class="space-y-3">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                Review threads · {{ conversation.threads.length }}
                <span v-if="unresolvedThreadCount" class="normal-case tracking-normal text-amber-700 dark:text-amber-400">
                    ({{ unresolvedThreadCount }} unresolved)
                </span>
            </h2>
            <div class="space-y-5">
                <div v-for="group in threadGroups" :key="group.path" class="space-y-2">
                    <div class="flex items-center gap-2 text-sm">
                        <FileCode2 class="size-4 shrink-0 text-muted-foreground" />
                        <span class="min-w-0 truncate font-mono text-xs font-medium" :title="group.path">{{ group.path }}</span>
                        <span class="shrink-0 text-xs text-muted-foreground">
                            {{ group.threads.length }} thread{{ group.threads.length === 1 ? '' : 's' }}
                        </span>
                    </div>
                    <div class="space-y-2">
                        <ReviewThreadCard
                            v-for="thread in group.threads"
                            :key="thread.id"
                            :thread="thread"
                            :pull-request-id="pullRequest.id"
                            :permissions="permissions"
                            :reply-drafts="replyDrafts"
                        />
                    </div>
                </div>
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Comments · {{ conversation.comments.length }}</h2>
            <div v-if="conversation.comments.length" class="divide-y rounded-lg border bg-card">
                <div v-for="comment in conversation.comments" :key="comment.node_id" class="px-4 py-3">
                    <CommentItem :comment="comment" />
                </div>
            </div>

            <div class="rounded-lg border bg-card p-3">
                <ReplyComposer
                    v-if="permissions.can_reply_to_comments"
                    :pull-request-id="pullRequest.id"
                    :draft-target="conversationDraftTarget"
                    :drafts="conversationDrafts"
                    :can-draft="permissions.can_run_review"
                    placeholder="Add a comment to the pull request…"
                    submit-label="Comment"
                />
                <SettingsHint v-else message="Commenting is off" />
            </div>
        </section>
    </div>
</template>
