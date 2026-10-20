<script setup lang="ts">
import ExternalLink from '@/components/ExternalLink.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Skeleton } from '@/components/ui/skeleton';
import { timeAgo } from '@/lib/pull-requests';
import { cn } from '@/lib/utils';
import { useStorage } from '@vueuse/core';
import { AlertCircle, AlertTriangle, Check, CheckSquare, GitPullRequestArrow, Lock, Minus, RefreshCw, Search } from 'lucide-vue-next';
import { computed, onMounted, ref, watch, type HTMLAttributes } from 'vue';

const props = withDefaults(
    defineProps<{
        /** Full names ("owner/name") to select once the list has loaded, e.g. repositories with review requests waiting. */
        preselect?: string[];
        /** Classes for the scrollable list, e.g. to change its maximum height. */
        listClass?: HTMLAttributes['class'];
    }>(),
    {
        preselect: () => [],
        listClass: undefined,
    },
);

/** The selected repositories, ready to submit. */
const selected = defineModel<RepositorySelection[]>({ required: true });

/** Rows are rendered in pages so accounts with hundreds of repositories stay responsive. */
const PAGE_SIZE = 100;

const repositories = ref<AvailableRepository[]>([]);
/** Selected repositories that GitHub no longer returned (archived, renamed or access removed). */
const unavailableIds = ref<Set<number>>(new Set());
const loading = ref(true);
const refreshing = ref(false);
const loadError = ref<string | null>(null);
/** Reasons repositories may be missing, e.g. an organisation that requires SSO authorisation for the token. */
const warnings = ref<{ key: string; message: string; url: string | null }[]>([]);
/** Warnings the user chose to hide, e.g. organisations they don't want to authorise. Remembered on this computer. */
const hiddenWarnings = useStorage<string[]>('prism:hidden-repository-warnings', []);
const visibleWarnings = computed(() => warnings.value.filter((warning) => !hiddenWarnings.value.includes(warning.key)));

function hideWarning(key: string): void {
    hiddenWarnings.value = [...new Set([...hiddenWarnings.value, key])];
}
const hasPreselected = ref(false);

const search = ref<string | number>('');
const owner = ref<string | number | null>('');
const onlyReviewRequests = ref(false);
const onlySelected = ref(false);
const renderLimit = ref(PAGE_SIZE);

const selectedIds = computed(() => new Set(selected.value.map((repository) => repository.github_id)));

/** Repositories with review requests first, otherwise GitHub's order (most recently pushed). */
const sortedRepositories = computed(() =>
    [...repositories.value].sort((first, second) => Number(second.has_review_requests) - Number(first.has_review_requests)),
);

const searchIndex = computed(
    () =>
        new Map(
            repositories.value.map((repository) => [repository.github_id, `${repository.full_name} ${repository.description ?? ''}`.toLowerCase()]),
        ),
);

const owners = computed(() => {
    const counts = new Map<string, number>();

    for (const repository of repositories.value) {
        counts.set(repository.owner, (counts.get(repository.owner) ?? 0) + 1);
    }

    return [...counts.entries()]
        .map(([name, count]) => ({ name, count }))
        .sort((first, second) => first.name.localeCompare(second.name, undefined, { sensitivity: 'base' }));
});

const reviewRequestCount = computed(() => repositories.value.filter((repository) => repository.has_review_requests).length);

const searchTerms = computed(() => String(search.value).trim().toLowerCase().split(/\s+/).filter(Boolean));

const hasActiveFilters = computed(() => searchTerms.value.length > 0 || Boolean(owner.value) || onlyReviewRequests.value || onlySelected.value);

const filteredRepositories = computed(() =>
    sortedRepositories.value.filter((repository) => {
        if (owner.value && repository.owner !== owner.value) {
            return false;
        }

        if (onlyReviewRequests.value && !repository.has_review_requests) {
            return false;
        }

        if (onlySelected.value && !selectedIds.value.has(repository.github_id)) {
            return false;
        }

        const haystack = searchIndex.value.get(repository.github_id) ?? '';

        return searchTerms.value.every((term) => haystack.includes(term));
    }),
);

const renderedRepositories = computed(() => filteredRepositories.value.slice(0, renderLimit.value));

const remainingCount = computed(() => filteredRepositories.value.length - renderedRepositories.value.length);

const filteredSelectionState = computed<boolean | 'indeterminate'>(() => {
    const selectedCount = filteredRepositories.value.filter((repository) => selectedIds.value.has(repository.github_id)).length;

    if (selectedCount === 0) {
        return false;
    }

    return selectedCount === filteredRepositories.value.length ? true : 'indeterminate';
});

watch([searchTerms, owner, onlyReviewRequests, onlySelected], () => {
    renderLimit.value = PAGE_SIZE;
});

async function load(refresh = false): Promise<void> {
    if (refresh) {
        refreshing.value = true;
    } else {
        loading.value = true;
    }

    loadError.value = null;

    try {
        const response = await fetch(route('github.repositories') + (refresh ? '?refresh=1' : ''), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const body = await response.json().catch(() => null);

        if (!response.ok || !Array.isArray(body?.repositories)) {
            throw new Error(
                typeof body?.message === 'string' && body.message !== ''
                    ? body.message
                    : `Your repositories could not be loaded from GitHub (error ${response.status}).`,
            );
        }

        setRepositories(body.repositories as AvailableRepository[]);
        warnings.value = Array.isArray(body.warnings) ? body.warnings : [];
    } catch (error) {
        loadError.value = error instanceof Error ? error.message : 'Your repositories could not be loaded from GitHub.';
    } finally {
        loading.value = false;
        refreshing.value = false;
    }
}

/**
 * Keep selected repositories GitHub didn't return visible so they can still be deselected,
 * then apply the initial preselection once.
 */
function setRepositories(available: AvailableRepository[]): void {
    const availableIds = new Set(available.map((repository) => repository.github_id));
    const missing = selected.value.filter((repository) => !availableIds.has(repository.github_id));

    unavailableIds.value = new Set(missing.map((repository) => repository.github_id));
    repositories.value = [
        ...available,
        ...missing.map((repository) => ({ ...repository, pushed_at: null, description: null, is_tracked: true, has_review_requests: false })),
    ];

    if (!hasPreselected.value) {
        hasPreselected.value = true;

        const preselected = new Set(props.preselect.map((fullName) => fullName.toLowerCase()));
        const additions = available.filter(
            (repository) => preselected.has(repository.full_name.toLowerCase()) && !selectedIds.value.has(repository.github_id),
        );

        if (additions.length > 0) {
            selected.value = [...selected.value, ...additions.map(toRepositorySelection)];
        }
    }
}

function toggle(repository: AvailableRepository, checked: boolean): void {
    if (checked && !selectedIds.value.has(repository.github_id)) {
        selected.value = [...selected.value, toRepositorySelection(repository)];
    } else if (!checked) {
        selected.value = selected.value.filter((selection) => selection.github_id !== repository.github_id);
    }
}

function toggleFiltered(checked: boolean): void {
    if (checked) {
        const additions = filteredRepositories.value.filter((repository) => !selectedIds.value.has(repository.github_id));
        selected.value = [...selected.value, ...additions.map(toRepositorySelection)];

        return;
    }

    const filteredIds = new Set(filteredRepositories.value.map((repository) => repository.github_id));
    selected.value = selected.value.filter((selection) => !filteredIds.has(selection.github_id));
}

function clearFilters(): void {
    search.value = '';
    owner.value = '';
    onlyReviewRequests.value = false;
    onlySelected.value = false;
}

function showMore(): void {
    renderLimit.value += PAGE_SIZE;
}

function onListScroll(event: Event): void {
    const list = event.target as HTMLElement;

    if (remainingCount.value > 0 && list.scrollTop + list.clientHeight >= list.scrollHeight - 240) {
        showMore();
    }
}

onMounted(() => load());

defineExpose({ reload: () => load(true) });
</script>

<script lang="ts">
import type { AvailableRepository, Repository } from '@/types';

/** The shape PUT /tracked-repositories expects for each repository. */
export type RepositorySelection = Pick<
    AvailableRepository,
    'github_id' | 'owner' | 'name' | 'full_name' | 'html_url' | 'default_branch' | 'is_private'
>;

export function toRepositorySelection(repository: AvailableRepository | Repository): RepositorySelection {
    return {
        github_id: repository.github_id,
        owner: repository.owner,
        name: repository.name,
        full_name: repository.full_name,
        html_url: repository.html_url,
        default_branch: repository.default_branch,
        is_private: repository.is_private,
    };
}

/** The tracked-repositories endpoint accepts at most this many repositories. */
export const MAX_TRACKED_REPOSITORIES = 500;
</script>

<template>
    <div class="flex flex-col gap-3">
        <div
            v-for="warning in visibleWarnings"
            :key="warning.key"
            class="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200"
        >
            <AlertTriangle class="mt-0.5 size-4 shrink-0" />
            <div class="flex-1 space-y-1.5">
                <p>{{ warning.message }}</p>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs font-medium">
                    <ExternalLink v-if="warning.url" :href="warning.url" class="underline underline-offset-2">Fix this on GitHub</ExternalLink>
                    <button type="button" class="underline underline-offset-2" :disabled="refreshing" @click="load(true)">
                        I've fixed it, refresh the list
                    </button>
                    <button type="button" class="opacity-80 hover:opacity-100" @click="hideWarning(warning.key)">Hide this warning</button>
                </div>
            </div>
        </div>
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <Search class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    v-model="search"
                    type="search"
                    placeholder="Search repositories…"
                    class="h-9 pl-8"
                    :disabled="loading"
                    aria-label="Search repositories"
                />
            </div>
            <NativeSelect v-model="owner" class="sm:w-52" aria-label="Filter by owner">
                <option value="">All owners ({{ repositories.length }})</option>
                <option v-for="option in owners" :key="option.name" :value="option.name">{{ option.name }} ({{ option.count }})</option>
            </NativeSelect>
            <Button
                type="button"
                variant="outline"
                size="icon"
                class="shrink-0"
                :disabled="loading || refreshing"
                title="Reload the list from GitHub"
                @click="load(true)"
            >
                <RefreshCw :class="{ 'animate-spin': refreshing }" />
                <span class="sr-only">Reload the list from GitHub</span>
            </Button>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button
                v-if="reviewRequestCount > 0"
                type="button"
                :aria-pressed="onlyReviewRequests"
                :class="
                    cn(
                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                        onlyReviewRequests
                            ? 'border-red-300 bg-red-50 text-red-800 dark:border-red-500/40 dark:bg-red-500/15 dark:text-red-300'
                            : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                    )
                "
                @click="onlyReviewRequests = !onlyReviewRequests"
            >
                <GitPullRequestArrow class="size-3.5" />
                Has review requests for you ({{ reviewRequestCount }})
            </button>
            <button
                type="button"
                :aria-pressed="onlySelected"
                :class="
                    cn(
                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                        onlySelected
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                    )
                "
                @click="onlySelected = !onlySelected"
            >
                <CheckSquare class="size-3.5" />
                Selected only
            </button>
            <button
                v-if="hasActiveFilters"
                type="button"
                class="text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                @click="clearFilters"
            >
                Clear filters
            </button>
            <span class="ml-auto text-xs text-muted-foreground">
                <span class="font-medium text-foreground">{{ selected.length }}</span> selected
            </span>
        </div>

        <div class="overflow-hidden rounded-lg border">
            <div v-if="loading" class="divide-y">
                <div v-for="index in 7" :key="index" class="flex items-center gap-3 px-3 py-3">
                    <Skeleton class="size-5 rounded-sm" />
                    <div class="flex-1 space-y-1.5">
                        <Skeleton class="h-3.5 w-2/5" />
                        <Skeleton class="h-3 w-3/5" />
                    </div>
                    <Skeleton class="h-3 w-20" />
                </div>
            </div>

            <div v-else-if="loadError" class="flex flex-col items-center gap-3 px-6 py-10 text-center">
                <AlertCircle class="size-6 text-red-600 dark:text-red-500" />
                <div class="space-y-1">
                    <p class="text-sm font-medium">Couldn't load your repositories</p>
                    <p class="text-sm text-muted-foreground">{{ loadError }}</p>
                </div>
                <Button type="button" variant="outline" size="sm" @click="load()">Try again</Button>
            </div>

            <div v-else-if="repositories.length === 0" class="px-6 py-10 text-center text-sm text-muted-foreground">
                This GitHub account can't see any repositories yet.
            </div>

            <template v-else>
                <label
                    class="relative flex cursor-pointer items-center gap-3 border-b bg-muted/40 px-3 py-2 text-xs font-medium text-muted-foreground"
                >
                    <Checkbox :checked="filteredSelectionState" :disabled="filteredRepositories.length === 0" @update:checked="toggleFiltered">
                        <Minus v-if="filteredSelectionState === 'indeterminate'" class="size-3.5 stroke-[3]" />
                        <Check v-else class="size-3.5 stroke-[3]" />
                    </Checkbox>
                    <span v-if="hasActiveFilters">Select all {{ filteredRepositories.length }} matching</span>
                    <span v-else>Select all {{ filteredRepositories.length }}</span>
                    <button
                        v-if="selected.length > 0"
                        type="button"
                        class="ml-auto font-normal underline-offset-4 hover:text-foreground hover:underline"
                        @click.prevent="selected = []"
                    >
                        Clear selection
                    </button>
                </label>

                <div :class="cn('relative max-h-[26rem] overflow-y-auto overscroll-contain', props.listClass)" @scroll.passive="onListScroll">
                    <div v-if="filteredRepositories.length === 0" class="flex flex-col items-center gap-2 px-6 py-10 text-center">
                        <p class="text-sm text-muted-foreground">No repositories match your filters.</p>
                        <Button type="button" variant="ghost" size="sm" @click="clearFilters">Clear filters</Button>
                    </div>

                    <ul v-else class="divide-y">
                        <li v-for="repository in renderedRepositories" :key="repository.github_id">
                            <label
                                :class="
                                    cn(
                                        'relative flex cursor-pointer items-start gap-3 px-3 py-2.5 transition-colors hover:bg-muted/50',
                                        selectedIds.has(repository.github_id) && 'bg-primary/[0.04]',
                                    )
                                "
                            >
                                <Checkbox
                                    class="mt-0.5"
                                    :checked="selectedIds.has(repository.github_id)"
                                    @update:checked="(checked: boolean) => toggle(repository, checked)"
                                />
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <span class="min-w-0 truncate text-sm">
                                            <span class="text-muted-foreground">{{ repository.owner }}/</span>
                                            <span class="font-medium">{{ repository.name }}</span>
                                        </span>
                                        <Badge v-if="repository.is_private" variant="muted"><Lock />Private</Badge>
                                        <Badge v-if="repository.has_review_requests" variant="danger"><GitPullRequestArrow />Review requested</Badge>
                                        <Badge v-if="unavailableIds.has(repository.github_id)" variant="warning">Not found on GitHub</Badge>
                                        <Badge v-else-if="repository.is_tracked" variant="outline">Tracked</Badge>
                                    </div>
                                    <p v-if="repository.description" class="mt-0.5 truncate text-xs text-muted-foreground">
                                        {{ repository.description }}
                                    </p>
                                </div>
                                <span v-if="repository.pushed_at" class="hidden shrink-0 pt-0.5 text-xs text-muted-foreground sm:block">
                                    Pushed {{ timeAgo(repository.pushed_at) }}
                                </span>
                            </label>
                        </li>
                    </ul>

                    <div v-if="remainingCount > 0" class="border-t p-2 text-center">
                        <Button type="button" variant="ghost" size="sm" @click="showMore">Show {{ Math.min(remainingCount, PAGE_SIZE) }} more</Button>
                    </div>
                </div>
            </template>
        </div>

        <p v-if="selected.length > MAX_TRACKED_REPOSITORIES" class="text-sm text-amber-700 dark:text-amber-400">
            You can track up to {{ MAX_TRACKED_REPOSITORIES }} repositories. Deselect {{ selected.length - MAX_TRACKED_REPOSITORIES }} to continue.
        </p>
    </div>
</template>
