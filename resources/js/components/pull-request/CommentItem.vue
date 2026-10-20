<script setup lang="ts">
import ExternalLink from '@/components/ExternalLink.vue';
import { timeAgo } from '@/lib/pull-requests';
import type { GithubComment } from '@/types';
import GithubAvatar from './GithubAvatar.vue';
import GithubMarkdown from './GithubMarkdown.vue';
import { formatDateTime } from './utils';

/** One GitHub comment, review or reply, with its GitHub-rendered body. */
defineProps<{
    comment: GithubComment;
}>();
</script>

<template>
    <article class="flex gap-3">
        <GithubAvatar :login="comment.author_login" :url="comment.author_avatar_url" class="mt-0.5" />
        <div class="min-w-0 flex-1 space-y-1">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                <span class="font-semibold text-foreground">{{ comment.author_login }}</span>
                <slot name="badge" />
                <ExternalLink
                    v-if="comment.url"
                    :href="comment.url"
                    class="text-muted-foreground hover:text-foreground hover:underline"
                    :title="formatDateTime(comment.created_at)"
                >
                    {{ timeAgo(comment.created_at) }}
                </ExternalLink>
                <span v-else class="text-muted-foreground" :title="formatDateTime(comment.created_at)">{{ timeAgo(comment.created_at) }}</span>
            </div>
            <GithubMarkdown v-if="comment.body_html" :html="comment.body_html" />
            <p v-else-if="comment.body" class="whitespace-pre-wrap text-sm">{{ comment.body }}</p>
        </div>
    </article>
</template>
