<script setup lang="ts">
import EmptyState from '@/components/pull-requests/EmptyState.vue';
import PullRequestFilters from '@/components/pull-requests/PullRequestFilters.vue';
import PullRequestRow from '@/components/pull-requests/PullRequestRow.vue';
import PullRequestStats from '@/components/pull-requests/PullRequestStats.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Switch } from '@/components/ui/switch';
import {
    attentionFilterOptions,
    isSnoozed,
    sortOptions,
    summarisePullRequests,
    usePullRequestFilters,
    type PullRequestSort,
    type PullRequestStatKey,
} from '@/composables/usePullRequestFilters';
import type { PullRequest } from '@/types';
import { Link } from '@inertiajs/vue3';
import { useEventListener, useStorage } from '@vueuse/core';
import { AlarmClock, ChevronRight, ChevronsDownUp, ChevronsUpDown, FilterX, GitPullRequest, SearchX, X } from 'lucide-vue-next';
import { computed, useTemplateRef, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        pullRequests: PullRequest[];
        /** Filters are remembered per page under this key. */
        storageKey: string;
        teams?: string[];
        /** When given, the filters include a repository select. */
        repositories?: { id: number; full_name: string }[] | null;
        canGroupByRepository?: boolean;
        showRepository?: boolean;
    }>(),
    {
        teams: () => [],
        repositories: null,
        canGroupByRepository: false,
        showRepository: true,
    },
);

const { filters, visiblePullRequests, activeFilterCount, updateFilter, clearFilters } = usePullRequestFilters(
    props.storageKey,
    () => props.pullRequests,
);

/** Closed and snoozed pull requests only count when "Recently closed" or "Snoozed" is on. */
const pullRequestsInScope = computed(() =>
    props.pullRequests.filter(
        (pullRequest) => (filters.value.showClosed || pullRequest.state === 'open') && (filters.value.showSnoozed || !isSnoozed(pullRequest)),
    ),
);

const snoozedCount = computed(() => props.pullRequests.filter((pullRequest) => pullRequest.state === 'open' && isSnoozed(pullRequest)).length);

/** Recently closed pull requests are sent too, but with none open the page shows its empty state, not empty filters. */
const hasOpenPullRequests = computed(() => props.pullRequests.some((pullRequest) => pullRequest.state === 'open'));
const closedCount = computed(() => props.pullRequests.filter((pullRequest) => pullRequest.state !== 'open').length);

const stats = computed(() => summarisePullRequests(pullRequestsInScope.value));

/** Forget a remembered repository filter once that repository is no longer listed (e.g. it was untracked). */
watch(
    () => props.repositories,
    (repositories) => {
        const selected = filters.value.repository;

        if (selected !== '' && !repositories?.some((repository) => String(repository.id) === selected)) {
            updateFilter('repository', '');
        }
    },
    { immediate: true },
);

interface ActiveFilter {
    key: string;
    label: string;
    remove: () => void;
}

/** Every filter that narrows the list, spelled out so it's obvious why pull requests are hidden. */
const activeFilters = computed<ActiveFilter[]>(() => {
    const current = filters.value;
    const active: ActiveFilter[] = [];

    if (current.search.trim() !== '') {
        active.push({ key: 'search', label: `Search: “${current.search.trim()}”`, remove: () => updateFilter('search', '') });
    }

    for (const attention of current.attention) {
        const label = attentionFilterOptions.find((option) => option.value === attention)?.label ?? attention;
        active.push({
            key: `attention:${attention}`,
            label: `Status: ${label}`,
            remove: () =>
                updateFilter(
                    'attention',
                    current.attention.filter((value) => value !== attention),
                ),
        });
    }

    if (current.repository !== '') {
        const repository = props.repositories?.find((candidate) => String(candidate.id) === current.repository);
        active.push({ key: 'repository', label: `Repository: ${repository?.full_name ?? 'unknown'}`, remove: () => updateFilter('repository', '') });
    }

    if (current.requested !== 'any') {
        const label =
            current.requested === 'direct'
                ? 'Requested from you directly'
                : current.requested === 'team'
                  ? 'Requested via any team'
                  : `Requested via @${current.requested.slice('team:'.length)}`;
        active.push({ key: 'requested', label, remove: () => updateFilter('requested', 'any') });
    }

    const toggles = [
        ['hideDrafts', 'Drafts hidden'],
        ['onlyWithFindings', 'With AI findings'],
        ['onlyFailingChecks', 'Failing checks'],
        ['showClosed', 'Including recently closed'],
        ['showSnoozed', 'Including snoozed'],
    ] as const;

    for (const [key, label] of toggles) {
        if (current[key]) {
            active.push({ key, label, remove: () => updateFilter(key, false) });
        }
    }

    return active;
});

const activeStats = computed<PullRequestStatKey[]>(() => {
    const active: PullRequestStatKey[] = [];

    if (filters.value.attention.length === 1) {
        const [attention] = filters.value.attention;

        if (attention === 'awaiting' || attention === 'approved' || attention === 'changes_requested' || attention === 'authored') {
            active.push(attention);
        }
    }

    if (filters.value.requested === 'team') {
        active.push('via_teams');
    }

    return active;
});

/** Clicking a summary card applies its filter, or removes it again when it is already applied. */
function selectStat(key: PullRequestStatKey): void {
    const isActive = activeStats.value.includes(key);

    if (key === 'via_teams') {
        updateFilter('requested', isActive ? 'any' : 'team');

        return;
    }

    updateFilter('attention', isActive ? [] : [key]);
}

interface RepositoryGroup {
    id: number;
    fullName: string;
    pullRequests: PullRequest[];
    awaiting: number;
    approved: number;
}

const isGrouped = computed(() => props.canGroupByRepository && filters.value.groupByRepository);

/** Groups keep the order of the sorted list, so the chosen sort decides which repository comes first. */
const groups = computed<RepositoryGroup[]>(() => {
    const byRepository = new Map<number, RepositoryGroup>();

    for (const pullRequest of visiblePullRequests.value) {
        const id = pullRequest.repository?.id ?? 0;
        let group = byRepository.get(id);

        if (!group) {
            group = { id, fullName: pullRequest.repository?.full_name ?? 'Unknown repository', pullRequests: [], awaiting: 0, approved: 0 };
            byRepository.set(id, group);
        }

        group.pullRequests.push(pullRequest);
        group.awaiting += pullRequest.attention === 'awaiting' ? 1 : 0;
        group.approved += pullRequest.attention === 'approved' ? 1 : 0;
    }

    return [...byRepository.values()];
});

const collapsedRepositories = useStorage<number[]>(`prism:collapsed-repositories:${props.storageKey}`, []);

function setGroupOpen(repositoryId: number, open: boolean): void {
    const others = collapsedRepositories.value.filter((id) => id !== repositoryId);

    collapsedRepositories.value = open ? others : [...others, repositoryId];
}

const areAllGroupsCollapsed = computed(
    () => groups.value.length > 0 && groups.value.every((group) => collapsedRepositories.value.includes(group.id)),
);

function toggleAllGroups(): void {
    collapsedRepositories.value = areAllGroupsCollapsed.value ? [] : groups.value.map((group) => group.id);
}

const rowsElement = useTemplateRef<HTMLElement>('rows');

function isTypingInField(target: EventTarget | null): boolean {
    return target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));
}

/**
 * j/k move focus to the next and previous pull request, starting at the first one, and ↓/↑ do the same while a row
 * has focus. Focus lands on the row's title link, so Enter opens it. Keys typed in a field, or in an open menu or
 * dialog, are left alone.
 */
useEventListener(document, 'keydown', (event: KeyboardEvent) => {
    const isNext = event.key === 'j' || event.key === 'ArrowDown';
    const isPrevious = event.key === 'k' || event.key === 'ArrowUp';

    if (!(isNext || isPrevious) || event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey || event.shiftKey) {
        return;
    }

    const target = event.target instanceof Element ? event.target : null;

    if (isTypingInField(target) || target?.closest('[role="dialog"], [role="alertdialog"], [role="menu"], [role="listbox"]')) {
        return;
    }

    // Rows in collapsed repository groups aren't rendered, so these are exactly the rows on screen, in order.
    const links = [...(rowsElement.value?.querySelectorAll<HTMLElement>('[data-pull-request-link]') ?? [])];
    const currentRow = target?.closest('[data-pull-request-row]');
    const current = currentRow ? links.findIndex((link) => currentRow.contains(link)) : -1;

    if (links.length === 0 || (current === -1 && event.key.startsWith('Arrow'))) {
        return;
    }

    const next = current === -1 ? 0 : Math.min(Math.max(current + (isNext ? 1 : -1), 0), links.length - 1);

    event.preventDefault();
    links[next].focus({ preventScroll: true });
    (links[next].closest('[data-pull-request-row]') ?? links[next]).scrollIntoView({ block: 'nearest' });
});
</script>

<template>
    <div class="flex flex-col gap-4">
        <template v-if="!hasOpenPullRequests && !filters.showClosed">
            <div class="rounded-lg border border-dashed">
                <slot name="empty">
                    <EmptyState :icon="GitPullRequest" title="No open pull requests" description="There is nothing here right now." />
                </slot>
            </div>
            <p v-if="closedCount > 0" class="text-center text-sm text-muted-foreground">
                {{ closedCount }} pull request{{ closedCount === 1 ? ' was' : 's were' }} closed or merged recently.
                <Button variant="link" class="h-auto p-0" @click="updateFilter('showClosed', true)">Show recently closed</Button>
            </p>
        </template>

        <template v-else>
            <PullRequestStats :stats="stats" :active="activeStats" @select="selectStat" />

            <PullRequestFilters v-model="filters" :stats="stats" :teams="teams" :repositories="repositories" :snoozed-count="snoozedCount" />

            <div class="flex flex-col gap-2">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2 text-xs text-muted-foreground">
                    <span aria-live="polite">
                        Showing <span class="font-medium tabular-nums text-foreground">{{ visiblePullRequests.length }}</span> of
                        <span class="tabular-nums">{{ pullRequestsInScope.length }}</span> pull request{{
                            pullRequestsInScope.length === 1 ? '' : 's'
                        }}
                    </span>
                    <div v-if="activeFilters.length > 0" class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Active filters">
                        <span>Filtered by</span>
                        <button
                            v-for="filter in activeFilters"
                            :key="filter.key"
                            type="button"
                            class="inline-flex h-6 items-center gap-1 rounded-full border border-primary/40 bg-primary/10 pl-2 pr-1.5 font-medium text-foreground transition-colors hover:bg-primary/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            :title="`Remove the filter “${filter.label}”`"
                            @click="filter.remove()"
                        >
                            {{ filter.label }}
                            <X class="size-3" aria-hidden="true" />
                        </button>
                        <Button variant="ghost" size="sm" class="h-6 gap-1 px-2 text-xs" @click="clearFilters">
                            <FilterX class="!size-3.5" />
                            Clear all
                        </Button>
                    </div>

                    <div class="ml-auto flex flex-wrap items-center gap-3">
                        <template v-if="canGroupByRepository">
                            <Button
                                v-if="isGrouped && groups.length > 1"
                                variant="ghost"
                                size="sm"
                                class="h-7 gap-1.5 px-2 text-xs"
                                @click="toggleAllGroups"
                            >
                                <component :is="areAllGroupsCollapsed ? ChevronsUpDown : ChevronsDownUp" class="!size-3.5" />
                                {{ areAllGroupsCollapsed ? 'Expand all' : 'Collapse all' }}
                            </Button>
                            <div class="flex items-center gap-2">
                                <Switch
                                    id="group-by-repository"
                                    :checked="filters.groupByRepository"
                                    @update:checked="(value: boolean) => updateFilter('groupByRepository', value)"
                                />
                                <Label for="group-by-repository" class="cursor-pointer text-xs font-medium text-foreground"
                                    >Group by repository</Label
                                >
                            </div>
                        </template>

                        <NativeSelect
                            :model-value="filters.sort"
                            class="w-52"
                            aria-label="Sort pull requests"
                            @update:model-value="(value) => updateFilter('sort', String(value ?? 'updated') as PullRequestSort)"
                        >
                            <option v-for="option in sortOptions" :key="option.value" :value="option.value">Sort: {{ option.label }}</option>
                        </NativeSelect>
                    </div>
                </div>

                <div ref="rows" class="overflow-hidden rounded-lg border bg-card shadow-sm">
                    <EmptyState
                        v-if="visiblePullRequests.length === 0 && activeFilterCount === 0 && snoozedCount > 0"
                        :icon="AlarmClock"
                        :title="`Everything here is snoozed (${snoozedCount})`"
                        description="Snoozed pull requests come back when the snooze ends or new commits are pushed."
                    >
                        <Button variant="outline" size="sm" @click="updateFilter('showSnoozed', true)">
                            <AlarmClock />
                            Show snoozed
                        </Button>
                    </EmptyState>
                    <EmptyState
                        v-else-if="visiblePullRequests.length === 0"
                        :icon="SearchX"
                        title="No pull requests match your filters"
                        description="Try removing a filter or searching for something else."
                    >
                        <Button variant="outline" size="sm" @click="clearFilters">
                            <FilterX />
                            Clear filters
                        </Button>
                    </EmptyState>

                    <div v-else-if="!isGrouped" class="divide-y">
                        <PullRequestRow
                            v-for="pullRequest in visiblePullRequests"
                            :key="pullRequest.id"
                            :pull-request="pullRequest"
                            :show-repository="showRepository"
                        />
                    </div>

                    <div v-else class="divide-y">
                        <Collapsible
                            v-for="group in groups"
                            :key="group.id"
                            v-slot="{ open }"
                            :open="!collapsedRepositories.includes(group.id)"
                            @update:open="(isOpen: boolean) => setGroupOpen(group.id, isOpen)"
                        >
                            <div class="flex items-center gap-2 bg-muted/40 py-2 pl-2 pr-4">
                                <CollapsibleTrigger
                                    class="flex min-w-0 flex-1 items-center gap-2 rounded-md px-2 py-1 text-left text-sm font-medium hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    <ChevronRight class="size-4 shrink-0 text-muted-foreground transition-transform" :class="{ 'rotate-90': open }" />
                                    <span class="truncate">{{ group.fullName }}</span>
                                    <span class="rounded-full bg-muted px-1.5 text-xs tabular-nums text-muted-foreground">{{
                                        group.pullRequests.length
                                    }}</span>
                                    <Badge v-if="group.awaiting > 0" variant="danger">{{ group.awaiting }} awaiting</Badge>
                                    <Badge v-if="group.approved > 0" variant="success">{{ group.approved }} approved</Badge>
                                </CollapsibleTrigger>
                                <Link
                                    v-if="group.id"
                                    :href="route('repositories.show', { repository: group.id })"
                                    class="shrink-0 text-xs text-muted-foreground hover:text-foreground hover:underline"
                                >
                                    View repository
                                </Link>
                            </div>
                            <CollapsibleContent>
                                <div class="divide-y border-t">
                                    <PullRequestRow
                                        v-for="pullRequest in group.pullRequests"
                                        :key="pullRequest.id"
                                        :pull-request="pullRequest"
                                        :show-repository="false"
                                    />
                                </div>
                            </CollapsibleContent>
                        </Collapsible>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
