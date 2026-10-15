<script setup lang="ts">
import EmptyState from '@/components/pull-requests/EmptyState.vue';
import PullRequestList from '@/components/pull-requests/PullRequestList.vue';
import UntrackedReviewRequestsNotice from '@/components/pull-requests/UntrackedReviewRequestsNotice.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { timeAgo } from '@/lib/pull-requests';
import type { BreadcrumbItem, PullRequest } from '@/types';
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { useNow } from '@vueuse/core';
import { Clock, FolderGit2, PartyPopper } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    pullRequests: PullRequest[];
    repositories: { id: number; full_name: string }[];
    /** "org/slug" teams the user belongs to. */
    teams: string[];
    /** "owner/name" repositories with review requests for the user that aren't tracked. */
    untrackedReviewRequests: string[];
    lastSyncedAt: string | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'My pull requests', href: route('dashboard') }];

// Pick up new review requests and AI review progress without a manual refresh.
usePoll(60000);

/** Re-renders the "last synced" label as time passes. */
const now = useNow({ interval: 30000 });

const lastSyncedLabel = computed<string | null>(() => (props.lastSyncedAt && now.value ? timeAgo(props.lastSyncedAt) : null));

const lastSyncedTitle = computed<string | undefined>(() => (props.lastSyncedAt ? new Date(props.lastSyncedAt).toLocaleString() : undefined));
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="My pull requests" />

        <template #header-actions>
            <span class="flex items-center gap-1.5 text-xs text-muted-foreground" :title="lastSyncedTitle">
                <Clock class="size-3.5" />
                {{ lastSyncedLabel ? `Last synced ${lastSyncedLabel}` : 'Not synced yet' }}
            </span>
        </template>

        <div class="mx-auto flex w-full max-w-screen-2xl flex-1 flex-col gap-4 p-4 lg:p-6">
            <div class="space-y-1">
                <h1 class="text-xl font-semibold tracking-tight">My pull requests</h1>
                <p class="text-sm text-muted-foreground">
                    Open pull requests across your {{ repositories.length }} tracked
                    {{ repositories.length === 1 ? 'repository' : 'repositories' }} that you opened, were asked to review (directly or via a team),
                    are assigned to, or have reviewed.
                </p>
            </div>

            <UntrackedReviewRequestsNotice :repositories="untrackedReviewRequests" />

            <PullRequestList
                :pull-requests="pullRequests"
                :repositories="repositories"
                :teams="teams"
                storage-key="dashboard"
                can-group-by-repository
            >
                <template #empty>
                    <EmptyState
                        v-if="repositories.length === 0"
                        :icon="FolderGit2"
                        title="You aren't tracking any repositories yet"
                        description="Choose the repositories you work in and the pull requests that need you will show up here."
                    >
                        <Button as-child size="sm">
                            <Link :href="route('settings.github')">Choose repositories</Link>
                        </Button>
                    </EmptyState>
                    <EmptyState
                        v-else
                        :icon="PartyPopper"
                        title="Nothing needs you right now"
                        description="No open pull requests in your tracked repositories involve you. New review requests appear here automatically."
                    >
                        <Button as-child size="sm" variant="outline">
                            <Link :href="route('repositories.index')">Browse repositories</Link>
                        </Button>
                    </EmptyState>
                </template>
            </PullRequestList>
        </div>
    </AppLayout>
</template>
