<script setup lang="ts">
import ExternalLink from '@/components/ExternalLink.vue';
import EmptyState from '@/components/pull-requests/EmptyState.vue';
import PullRequestList from '@/components/pull-requests/PullRequestList.vue';
import RepositoryReviewPromptDialog from '@/components/RepositoryReviewPromptDialog.vue';
import { Badge } from '@/components/ui/badge';
import { buttonVariants } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { timeAgo } from '@/lib/pull-requests';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem, PullRequest, Repository, RepositoryReviewPrompt } from '@/types';
import { Head, usePoll } from '@inertiajs/vue3';
import { useNow } from '@vueuse/core';
import { Clock, FolderGit2, FolderOpen, GitBranch, GitPullRequest, Github, Globe, Lock, ScrollText } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    repository: Repository;
    /** Every open pull request in the repository (not only the ones involving the user), plus ones closed in the last two weeks. */
    pullRequests: PullRequest[];
    /** "org/slug" teams the user belongs to. */
    teams: string[];
    reviewPrompt: RepositoryReviewPrompt;
}>();

const editingReviewPrompt = ref(false);

const openCount = computed(() => props.pullRequests.filter((pullRequest) => pullRequest.state === 'open').length);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Repositories', href: route('repositories.index') },
    { title: props.repository.full_name, href: route('repositories.show', { repository: props.repository.id }) },
]);

// Pick up new pull requests, review requests and AI review progress without a manual refresh.
usePoll(60000);

/** Re-renders the "synced" label as time passes. */
const now = useNow({ interval: 30000 });

const syncedLabel = computed<string | null>(() => (props.repository.synced_at && now.value ? timeAgo(props.repository.synced_at) : null));

const outlineButton = cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'h-8');
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="repository.full_name" />

        <template #header-actions>
            <span
                class="flex items-center gap-1.5 text-xs text-muted-foreground"
                :title="repository.synced_at ? new Date(repository.synced_at).toLocaleString() : undefined"
            >
                <Clock class="size-3.5" />
                {{ syncedLabel ? `Synced ${syncedLabel}` : 'Not synced yet' }}
            </span>
        </template>

        <div class="mx-auto flex w-full max-w-screen-2xl flex-1 flex-col gap-4 p-4 lg:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0 grow basis-96 space-y-1.5">
                    <div class="flex min-w-0 items-center gap-2">
                        <FolderGit2 class="size-5 shrink-0 text-muted-foreground" />
                        <h1 class="truncate text-xl font-semibold tracking-tight">
                            <span class="font-normal text-muted-foreground">{{ repository.owner }}/</span>{{ repository.name }}
                        </h1>
                        <Badge :variant="repository.is_private ? 'outline' : 'muted'" class="shrink-0">
                            <component :is="repository.is_private ? Lock : Globe" />
                            {{ repository.is_private ? 'Private' : 'Public' }}
                        </Badge>
                    </div>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        <span class="flex items-center gap-1">
                            <GitPullRequest class="size-3.5" />
                            {{ openCount }} open pull {{ openCount === 1 ? 'request' : 'requests' }}
                        </span>
                        <span v-if="repository.default_branch" class="flex items-center gap-1 font-mono text-[11px]">
                            <GitBranch class="size-3.5" />
                            {{ repository.default_branch }}
                        </span>
                        <span
                            v-if="repository.local_path"
                            class="flex min-w-0 items-center gap-1"
                            :title="`Local checkout: ${repository.local_path}`"
                        >
                            <FolderOpen class="size-3.5 shrink-0" />
                            <span class="max-w-72 truncate font-mono text-[11px]">{{ repository.local_path }}</span>
                        </span>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" :class="outlineButton" aria-haspopup="dialog" @click="editingReviewPrompt = true">
                        <ScrollText />
                        Review prompt
                        <Badge v-if="reviewPrompt.custom !== null" variant="info">Custom</Badge>
                    </button>
                    <ExternalLink :href="`${repository.html_url}/pulls`" :class="outlineButton">
                        <GitPullRequest />
                        Pull requests on GitHub
                    </ExternalLink>
                    <ExternalLink :href="repository.html_url" :class="outlineButton">
                        <Github />
                        Open on GitHub
                    </ExternalLink>
                </div>
            </div>

            <PullRequestList :pull-requests="pullRequests" :teams="teams" :storage-key="`repository:${repository.id}`" :show-repository="false">
                <template #empty>
                    <EmptyState
                        :icon="GitPullRequest"
                        :title="`No open pull requests in ${repository.full_name}`"
                        description="New pull requests appear here after the next sync."
                    >
                        <ExternalLink :href="repository.html_url" :class="outlineButton">
                            <Github />
                            Open on GitHub
                        </ExternalLink>
                    </EmptyState>
                </template>
            </PullRequestList>
        </div>

        <RepositoryReviewPromptDialog v-model:open="editingReviewPrompt" :repository="repository" :review-prompt="reviewPrompt" />
    </AppLayout>
</template>
