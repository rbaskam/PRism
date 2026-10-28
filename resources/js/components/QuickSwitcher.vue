<script setup lang="ts">
import AttentionBadge from '@/components/pull-requests/AttentionBadge.vue';
import EmptyState from '@/components/pull-requests/EmptyState.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { useQuickSwitcher } from '@/composables/useQuickSwitcher';
import { getJson } from '@/lib/http';
import { attentionStyles, closedStateLabel } from '@/lib/pull-requests';
import { cn } from '@/lib/utils';
import type { ReviewAttention } from '@/types';
import { router } from '@inertiajs/vue3';
import { useEventListener } from '@vueuse/core';
import {
    Bell,
    Bot,
    FileCode2,
    FolderGit2,
    Github,
    GitMerge,
    GitPullRequest,
    GitPullRequestClosed,
    GitPullRequestDraft,
    Inbox,
    Info,
    LayoutGrid,
    Palette,
    RefreshCw,
    Search,
    SearchX,
    ShieldCheck,
    type LucideIcon,
} from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';

/**
 * The ⌘K quick switcher: jump to any page, tracked repository or pull request by typing a few words. Repositories and
 * pull requests come from the database when it opens, so it never waits on GitHub.
 */

interface SwitcherRepository {
    id: number;
    full_name: string;
    counts: { open: number; awaiting: number; approved: number };
}

interface SwitcherPullRequest {
    id: number;
    repository: string;
    number: number;
    title: string;
    state: 'open' | 'closed';
    merged_at: string | null;
    is_draft: boolean;
    author_login: string | null;
    head_ref: string | null;
    attention: ReviewAttention;
}

interface SwitcherData {
    repositories: SwitcherRepository[];
    pull_requests: SwitcherPullRequest[];
}

interface PageItem {
    kind: 'page';
    key: string;
    href: string;
    title: string;
    icon: LucideIcon;
    haystack: string;
}

interface RepositoryItem {
    kind: 'repository';
    key: string;
    href: string;
    repository: SwitcherRepository;
    haystack: string;
}

interface PullRequestItem {
    kind: 'pull-request';
    key: string;
    href: string;
    pullRequest: SwitcherPullRequest;
    haystack: string;
}

type SwitcherItem = PageItem | RepositoryItem | PullRequestItem;

interface ResultGroup {
    key: string;
    label: string;
    items: { item: SwitcherItem; index: number }[];
}

/** More than this many matching pull requests are cut off, so the list stays quick to render and scan. */
const MAX_PULL_REQUESTS = 50;

const { isOpen, toggle } = useQuickSwitcher();

const pageItems: PageItem[] = [
    { title: 'My pull requests', href: route('dashboard'), icon: LayoutGrid, keywords: 'dashboard home review requests' },
    { title: 'Repositories', href: route('repositories.index'), icon: FolderGit2, keywords: '' },
    { title: 'Inbox', href: route('inbox.index'), icon: Inbox, keywords: 'github notifications' },
    { title: 'GitHub settings', href: route('settings.github'), icon: Github, keywords: 'account tracked repositories local checkouts cli' },
    { title: 'Claude settings', href: route('settings.claude'), icon: Bot, keywords: 'ai model api key subscription token' },
    { title: 'PR review settings', href: route('settings.review'), icon: FileCode2, keywords: 'prompt permissions posting comments code changes' },
    { title: 'Notification settings', href: route('settings.notifications'), icon: Bell, keywords: 'alerts desktop sound' },
    { title: 'Security settings', href: route('settings.security'), icon: ShieldCheck, keywords: 'app lock pin touch id' },
    { title: 'Appearance settings', href: route('appearance'), icon: Palette, keywords: 'theme dark light mode accent colour color' },
    { title: 'About', href: route('settings.about'), icon: Info, keywords: 'settings version updates changelog' },
].map(({ keywords, ...page }) => ({ ...page, kind: 'page' as const, key: `page:${page.href}`, haystack: `${page.title} ${keywords}`.toLowerCase() }));

const query = ref('');
const activeIndex = ref(0);
const data = ref<SwitcherData | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
let latestRequest = 0;

async function load(): Promise<void> {
    const request = ++latestRequest;
    loading.value = true;
    error.value = null;

    try {
        const result = await getJson<SwitcherData>(route('quick-switcher'), "Couldn't load your repositories and pull requests");

        if (request === latestRequest) {
            data.value = result;
        }
    } catch (failure) {
        if (request === latestRequest) {
            error.value = failure instanceof Error ? failure.message : "Couldn't load your repositories and pull requests.";
        }
    } finally {
        if (request === latestRequest) {
            loading.value = false;
        }
    }
}

watch(
    isOpen,
    (open) => {
        if (open) {
            query.value = '';
            activeIndex.value = 0;
            load();
        }
    },
    { immediate: true },
);

const repositoryItems = computed<RepositoryItem[]>(() =>
    (data.value?.repositories ?? []).map((repository) => ({
        kind: 'repository',
        key: `repository:${repository.id}`,
        href: route('repositories.show', { repository: repository.id }),
        repository,
        haystack: repository.full_name.toLowerCase(),
    })),
);

const pullRequestItems = computed<PullRequestItem[]>(() =>
    (data.value?.pull_requests ?? []).map((pullRequest) => ({
        kind: 'pull-request',
        key: `pull-request:${pullRequest.id}`,
        href: route('pull-requests.show', { pullRequest: pullRequest.id }),
        pullRequest,
        haystack: [pullRequest.repository, `#${pullRequest.number}`, pullRequest.title, pullRequest.author_login, pullRequest.head_ref]
            .filter(Boolean)
            .join('\n')
            .toLowerCase(),
    })),
);

/** Every typed word has to appear somewhere: "api 42" finds acme/api#42, "login octocat" a pull request by octocat. */
const terms = computed(() => query.value.toLowerCase().split(/\s+/).filter(Boolean));

function matches(item: SwitcherItem): boolean {
    return terms.value.every((term) => item.haystack.includes(term));
}

/** "42" or "#42" puts pull request #42 above the ones that merely contain 42 (#142, "Upgrade to v4.2"…). */
function isNumberMatch(item: PullRequestItem): boolean {
    return terms.value.some((term) => term.replace(/^#/, '') === String(item.pullRequest.number));
}

const matchingPullRequests = computed(() =>
    pullRequestItems.value.filter(matches).sort((a, b) => Number(isNumberMatch(b)) - Number(isNumberMatch(a))),
);

const groups = computed<ResultGroup[]>(() => {
    let index = 0;
    const withIndex = (items: SwitcherItem[]) => items.map((item) => ({ item, index: index++ }));

    return [
        { key: 'pages', label: 'Pages', items: withIndex(pageItems.filter(matches)) },
        { key: 'repositories', label: 'Repositories', items: withIndex(repositoryItems.value.filter(matches)) },
        { key: 'pull-requests', label: 'Pull requests', items: withIndex(matchingPullRequests.value.slice(0, MAX_PULL_REQUESTS)) },
    ].filter((group) => group.items.length > 0);
});

const results = computed(() => groups.value.flatMap((group) => group.items.map(({ item }) => item)));
const hiddenPullRequests = computed(() => Math.max(0, matchingPullRequests.value.length - MAX_PULL_REQUESTS));
const isLoadingFirstTime = computed(() => loading.value && data.value === null);

function optionId(index: number): string {
    return `quick-switcher-option-${index}`;
}

const activeOptionId = computed(() => (results.value.length > 0 ? optionId(activeIndex.value) : undefined));

watch(query, () => (activeIndex.value = 0));

watch(results, (items) => {
    if (activeIndex.value >= items.length) {
        activeIndex.value = 0;
    }
});

watch(activeIndex, (index) => nextTick(() => document.getElementById(optionId(index))?.scrollIntoView({ block: 'nearest' })));

function openItem(item: SwitcherItem): void {
    isOpen.value = false;
    router.visit(item.href);
}

function onKeydown(event: KeyboardEvent): void {
    const count = results.value.length;

    if (event.isComposing || count === 0) {
        return;
    }

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex.value = (activeIndex.value + (event.key === 'ArrowDown' ? 1 : -1) + count) % count;
    } else if (event.key === 'Enter') {
        event.preventDefault();
        openItem(results.value[activeIndex.value]);
    }
}

function pullRequestIcon(pullRequest: SwitcherPullRequest): LucideIcon {
    if (pullRequest.state !== 'open') {
        return pullRequest.merged_at ? GitMerge : GitPullRequestClosed;
    }

    return pullRequest.is_draft ? GitPullRequestDraft : GitPullRequest;
}

/** ⌘K (Ctrl+K on Windows) opens the switcher from anywhere, even while typing, and closes it again. */
useEventListener(window, 'keydown', (event: KeyboardEvent) => {
    if (event.key?.toLowerCase() !== 'k' || !(event.metaKey || event.ctrlKey) || event.altKey || event.shiftKey) {
        return;
    }

    // Leave another open dialog, e.g. one with a review being written, in charge until it's closed.
    if (!isOpen.value && document.querySelector('[role="dialog"][data-state="open"], [role="alertdialog"][data-state="open"]')) {
        return;
    }

    event.preventDefault();
    toggle();
});
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent class="max-w-xl gap-0 overflow-hidden p-0">
            <DialogTitle class="sr-only">Quick switcher</DialogTitle>
            <DialogDescription class="sr-only">
                Go to a page, repository or pull request. Type to filter, use the up and down arrow keys to choose, and press Enter to open it.
            </DialogDescription>

            <div class="flex items-center gap-2 border-b pl-4 pr-12">
                <Search class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                <input
                    v-model="query"
                    type="text"
                    role="combobox"
                    aria-label="Search pages, repositories and pull requests"
                    aria-autocomplete="list"
                    aria-controls="quick-switcher-results"
                    :aria-expanded="results.length > 0"
                    :aria-activedescendant="activeOptionId"
                    autocomplete="off"
                    spellcheck="false"
                    placeholder="Go to a page, repository or pull request…"
                    class="h-12 min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                    @keydown="onKeydown"
                />
            </div>

            <div class="h-[min(26rem,60vh)] overflow-y-auto overscroll-contain p-2">
                <div
                    v-if="error"
                    class="mb-2 flex items-center justify-between gap-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"
                    role="alert"
                >
                    <span>{{ error }}</span>
                    <Button variant="outline" size="sm" class="h-7 shrink-0" :disabled="loading" @click="load">
                        <RefreshCw :class="{ 'animate-spin': loading }" />
                        Try again
                    </Button>
                </div>

                <div id="quick-switcher-results" role="listbox" aria-label="Pages, repositories and pull requests">
                    <div v-for="group in groups" :key="group.key" role="group" :aria-labelledby="`quick-switcher-group-${group.key}`" class="pb-2">
                        <div
                            :id="`quick-switcher-group-${group.key}`"
                            role="presentation"
                            class="px-2 pb-1 pt-1.5 text-xs font-medium text-muted-foreground"
                        >
                            {{ group.label }}
                        </div>
                        <div
                            v-for="{ item, index } in group.items"
                            :id="optionId(index)"
                            :key="item.key"
                            role="option"
                            :aria-selected="index === activeIndex"
                            :class="
                                cn(
                                    'flex cursor-pointer select-none items-center gap-3 rounded-md px-2 py-2 text-sm text-foreground',
                                    index === activeIndex && 'bg-primary/10 ring-1 ring-inset ring-primary/40',
                                )
                            "
                            @mousemove="activeIndex = index"
                            @mousedown.prevent
                            @click="openItem(item)"
                        >
                            <template v-if="item.kind === 'page'">
                                <component :is="item.icon" class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                                <span class="min-w-0 flex-1 truncate">{{ item.title }}</span>
                            </template>

                            <template v-else-if="item.kind === 'repository'">
                                <FolderGit2 class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                                <span class="min-w-0 flex-1 truncate">{{ item.repository.full_name }}</span>
                                <span class="shrink-0 text-xs tabular-nums text-muted-foreground">{{ item.repository.counts.open }} open</span>
                                <Badge v-if="item.repository.counts.awaiting > 0" variant="danger" class="shrink-0">
                                    <span class="size-1.5 rounded-full" :class="attentionStyles.awaiting.dot" aria-hidden="true" />
                                    {{ item.repository.counts.awaiting }} awaiting
                                </Badge>
                            </template>

                            <template v-else>
                                <component :is="pullRequestIcon(item.pullRequest)" class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-medium">{{ item.pullRequest.title }}</span>
                                    <span class="flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground">
                                        <span class="truncate">{{ item.pullRequest.repository }}</span>
                                        <span class="shrink-0 tabular-nums">#{{ item.pullRequest.number }}</span>
                                        <span aria-hidden="true">·</span>
                                        <span class="shrink-0">{{ item.pullRequest.author_login ?? 'unknown' }}</span>
                                        <template v-if="item.pullRequest.head_ref">
                                            <span aria-hidden="true">·</span>
                                            <span class="truncate font-mono text-[11px]">{{ item.pullRequest.head_ref }}</span>
                                        </template>
                                    </span>
                                </span>
                                <span class="flex shrink-0 items-center gap-1.5">
                                    <Badge v-if="closedStateLabel(item.pullRequest)" :variant="closedStateLabel(item.pullRequest)!.badge">{{
                                        closedStateLabel(item.pullRequest)!.label
                                    }}</Badge>
                                    <Badge v-else-if="item.pullRequest.is_draft" variant="outline" class="text-muted-foreground">Draft</Badge>
                                    <AttentionBadge v-if="item.pullRequest.attention !== 'none'" :attention="item.pullRequest.attention" />
                                </span>
                            </template>
                        </div>
                    </div>
                </div>

                <div v-if="isLoadingFirstTime" class="space-y-3 px-2 py-2" role="status" aria-label="Loading repositories and pull requests">
                    <p class="text-xs font-medium text-muted-foreground">Loading repositories and pull requests…</p>
                    <div v-for="row in 3" :key="row" class="flex items-center gap-3">
                        <Skeleton class="size-4 rounded-full" />
                        <div class="flex-1 space-y-1.5">
                            <Skeleton class="h-3 w-2/3" />
                            <Skeleton class="h-3 w-1/3" />
                        </div>
                    </div>
                </div>

                <EmptyState
                    v-else-if="results.length === 0"
                    :icon="SearchX"
                    :title="`Nothing matches “${query.trim()}”`"
                    description="Search by page, repository, pull request number, title, author or branch."
                />

                <p v-if="hiddenPullRequests > 0" class="px-2 pb-1 text-xs text-muted-foreground">
                    {{ hiddenPullRequests }} more pull request{{ hiddenPullRequests === 1 ? '' : 's' }} match. Keep typing to narrow them down.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t px-4 py-2 text-xs text-muted-foreground" aria-hidden="true">
                <span class="flex items-center gap-1">
                    <kbd class="rounded border bg-muted px-1 font-mono text-[10px]">↑</kbd>
                    <kbd class="rounded border bg-muted px-1 font-mono text-[10px]">↓</kbd>
                    to move
                </span>
                <span class="flex items-center gap-1"><kbd class="rounded border bg-muted px-1 font-mono text-[10px]">↵</kbd> to open</span>
                <span class="flex items-center gap-1"><kbd class="rounded border bg-muted px-1 font-mono text-[10px]">esc</kbd> to close</span>
            </div>
        </DialogContent>
    </Dialog>
</template>
