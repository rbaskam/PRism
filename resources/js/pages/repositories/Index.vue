<script setup lang="ts">
import EmptyState from '@/components/pull-requests/EmptyState.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { attentionStyles, timeAgo } from '@/lib/pull-requests';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem, Repository } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { onKeyStroke } from '@vueuse/core';
import { Clock, FolderGit2, GitBranch, Globe, Lock, Search, SearchX, Settings2, X } from 'lucide-vue-next';
import { computed, ref, useTemplateRef } from 'vue';

const props = defineProps<{
    repositories: Repository[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Repositories', href: route('repositories.index') }];

const search = ref('');
const searchInput = useTemplateRef<InstanceType<typeof Input>>('searchInput');

function openCount(repository: Repository): number {
    return repository.counts?.open ?? 0;
}

function awaitingCount(repository: Repository): number {
    return repository.counts?.awaiting ?? 0;
}

function approvedCount(repository: Repository): number {
    return repository.counts?.approved ?? 0;
}

/** Repositories waiting on the user's review first, then the busiest. */
const sortedRepositories = computed(() =>
    [...props.repositories].sort(
        (a, b) => awaitingCount(b) - awaitingCount(a) || openCount(b) - openCount(a) || a.full_name.localeCompare(b.full_name),
    ),
);

const visibleRepositories = computed(() => {
    const terms = search.value.toLowerCase().split(/\s+/).filter(Boolean);

    return sortedRepositories.value.filter((repository) => terms.every((term) => repository.full_name.toLowerCase().includes(term)));
});

const totals = computed(() => ({
    open: props.repositories.reduce((total, repository) => total + openCount(repository), 0),
    awaiting: props.repositories.reduce((total, repository) => total + awaitingCount(repository), 0),
}));

function cardBorder(repository: Repository): string {
    if (awaitingCount(repository) > 0) {
        return attentionStyles.awaiting.border;
    }

    return approvedCount(repository) > 0 ? attentionStyles.approved.border : 'border-l-transparent';
}

onKeyStroke('/', (event) => {
    const target = event.target;
    const isTyping = target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));

    if (isTyping || event.metaKey || event.ctrlKey || event.altKey) {
        return;
    }

    event.preventDefault();
    (searchInput.value?.$el as HTMLInputElement | undefined)?.focus();
});

function onSearchEscape(event: KeyboardEvent): void {
    if (search.value !== '') {
        search.value = '';

        return;
    }

    (event.target as HTMLElement).blur();
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Repositories" />

        <template #header-actions>
            <Button as-child variant="outline" size="sm" class="h-8">
                <Link :href="route('settings.github')">
                    <Settings2 />
                    Manage repositories
                </Link>
            </Button>
        </template>

        <div class="mx-auto flex w-full max-w-screen-2xl flex-1 flex-col gap-4 p-4 lg:p-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div class="space-y-1">
                    <h1 class="text-xl font-semibold tracking-tight">Repositories</h1>
                    <p v-if="repositories.length > 0" class="text-sm text-muted-foreground">
                        {{ repositories.length }} tracked {{ repositories.length === 1 ? 'repository' : 'repositories' }} · {{ totals.open }} open
                        pull {{ totals.open === 1 ? 'request' : 'requests' }}
                        <template v-if="totals.awaiting > 0">
                            ·
                            <span class="font-medium text-red-600 dark:text-red-400">{{ totals.awaiting }} awaiting your review</span>
                        </template>
                    </p>
                </div>

                <div v-if="repositories.length > 0" class="relative w-full sm:w-72">
                    <Search class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        ref="searchInput"
                        v-model="search"
                        type="search"
                        placeholder="Filter repositories…"
                        aria-label="Filter repositories"
                        class="h-9 pl-8 pr-9 [&::-webkit-search-cancel-button]:hidden"
                        @keydown.esc="onSearchEscape"
                    />
                    <button
                        v-if="search"
                        type="button"
                        class="absolute right-2 top-1/2 -translate-y-1/2 rounded p-0.5 text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        title="Clear search"
                        @click="search = ''"
                    >
                        <X class="size-4" />
                        <span class="sr-only">Clear search</span>
                    </button>
                    <kbd
                        v-else
                        class="pointer-events-none absolute right-2.5 top-1/2 hidden -translate-y-1/2 rounded border bg-muted px-1.5 font-mono text-[10px] leading-4 text-muted-foreground sm:block"
                    >
                        /
                    </kbd>
                </div>
            </div>

            <div v-if="repositories.length === 0" class="rounded-lg border border-dashed">
                <EmptyState
                    :icon="FolderGit2"
                    title="You aren't tracking any repositories yet"
                    description="Pick the GitHub repositories you work in to see their pull requests and review requests here."
                >
                    <Button as-child size="sm">
                        <Link :href="route('settings.github')">Add repositories</Link>
                    </Button>
                </EmptyState>
            </div>

            <div v-else-if="visibleRepositories.length === 0" class="rounded-lg border border-dashed">
                <EmptyState :icon="SearchX" :title="`No repositories match “${search}”`" description="Try a different owner or repository name.">
                    <Button variant="outline" size="sm" @click="search = ''">Clear search</Button>
                </EmptyState>
            </div>

            <div v-else class="grid grid-cols-[repeat(auto-fill,minmax(17rem,1fr))] gap-3">
                <Link
                    v-for="repository in visibleRepositories"
                    :key="repository.id"
                    :href="route('repositories.show', { repository: repository.id })"
                    :class="
                        cn(
                            'flex flex-col gap-3 rounded-lg border border-l-[3px] bg-card p-4 shadow-sm transition-colors hover:bg-accent/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                            cardBorder(repository),
                        )
                    "
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex min-w-0 items-center gap-2">
                            <FolderGit2 class="size-4 shrink-0 text-muted-foreground" />
                            <span class="truncate text-sm" :title="repository.full_name">
                                <span class="text-muted-foreground">{{ repository.owner }}/</span>
                                <span class="font-semibold">{{ repository.name }}</span>
                            </span>
                        </div>
                        <Badge :variant="repository.is_private ? 'outline' : 'muted'" class="shrink-0">
                            <component :is="repository.is_private ? Lock : Globe" />
                            {{ repository.is_private ? 'Private' : 'Public' }}
                        </Badge>
                    </div>

                    <div class="flex items-baseline gap-1.5">
                        <span
                            class="text-2xl font-semibold tabular-nums leading-none"
                            :class="{ 'text-muted-foreground/50': openCount(repository) === 0 }"
                        >
                            {{ openCount(repository) }}
                        </span>
                        <span class="text-sm text-muted-foreground">open pull {{ openCount(repository) === 1 ? 'request' : 'requests' }}</span>
                    </div>

                    <div class="flex min-h-[22px] flex-wrap items-center gap-1.5">
                        <Badge v-if="awaitingCount(repository) > 0" variant="danger">
                            <span class="size-1.5 rounded-full" :class="attentionStyles.awaiting.dot" />
                            {{ awaitingCount(repository) }} awaiting your review
                        </Badge>
                        <Badge v-if="approvedCount(repository) > 0" variant="success">
                            <span class="size-1.5 rounded-full" :class="attentionStyles.approved.dot" />
                            {{ approvedCount(repository) }} approved by you
                        </Badge>
                        <span v-if="awaitingCount(repository) === 0 && approvedCount(repository) === 0" class="text-xs text-muted-foreground">
                            Nothing waiting on you
                        </span>
                    </div>

                    <div class="mt-auto flex items-center justify-between gap-2 border-t pt-3 text-xs text-muted-foreground">
                        <span
                            class="flex items-center gap-1"
                            :title="repository.synced_at ? new Date(repository.synced_at).toLocaleString() : undefined"
                        >
                            <Clock class="size-3.5" />
                            {{ repository.synced_at ? `Synced ${timeAgo(repository.synced_at)}` : 'Not synced yet' }}
                        </span>
                        <span v-if="repository.default_branch" class="flex min-w-0 items-center gap-1 font-mono text-[11px]">
                            <GitBranch class="size-3.5 shrink-0" />
                            <span class="truncate">{{ repository.default_branch }}</span>
                        </span>
                    </div>
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
