import type { PullRequest, ReviewAttention } from '@/types';
import { useStorage } from '@vueuse/core';
import { computed, toValue, type MaybeRefOrGetter } from 'vue';

/** "any", "direct" (asked by name), "team" (via any team) or "team:org/slug" (via one team). */
export type RequestedFilter = 'any' | 'direct' | 'team' | `team:${string}`;

export type PullRequestSort = 'updated' | 'oldest' | 'created' | 'largest' | 'findings' | 'awaiting_first';

export interface PullRequestFilterState {
    search: string;
    attention: ReviewAttention[];
    /** Repository id as a string ('' for every repository) so it binds straight to a select. */
    repository: string;
    requested: RequestedFilter;
    hideDrafts: boolean;
    onlyWithFindings: boolean;
    onlyFailingChecks: boolean;
    /** Also show pull requests closed or merged in the last two weeks (e.g. to get back to their AI reviews). */
    showClosed: boolean;
    /** Also show pull requests the user snoozed. */
    showSnoozed: boolean;
    sort: PullRequestSort;
    groupByRepository: boolean;
}

/** The summary cards above the list; each one maps onto a filter. */
export type PullRequestStatKey = 'awaiting' | 'approved' | 'changes_requested' | 'authored' | 'via_teams';

export type PullRequestStats = Record<PullRequestStatKey, number> & {
    /** Approved pull requests that received commits after the approval. */
    approved_with_new_commits: number;
    by_attention: Record<ReviewAttention, number>;
};

export const sortOptions: { value: PullRequestSort; label: string }[] = [
    { value: 'updated', label: 'Recently updated' },
    { value: 'oldest', label: 'Oldest first' },
    { value: 'created', label: 'Created newest' },
    { value: 'largest', label: 'Largest change' },
    { value: 'findings', label: 'Most AI findings' },
    { value: 'awaiting_first', label: 'Awaiting review first' },
];

export const attentionFilterOptions: { value: ReviewAttention; label: string }[] = [
    { value: 'awaiting', label: 'Awaiting' },
    { value: 'approved', label: 'Approved' },
    { value: 'changes_requested', label: 'Changes requested' },
    { value: 'commented', label: 'Commented' },
    { value: 'authored', label: 'Mine' },
    { value: 'none', label: 'Other' },
];

export function defaultPullRequestFilters(): PullRequestFilterState {
    return {
        search: '',
        attention: [],
        repository: '',
        requested: 'any',
        hideDrafts: false,
        onlyWithFindings: false,
        onlyFailingChecks: false,
        showClosed: false,
        showSnoozed: false,
        sort: 'updated',
        groupByRepository: false,
    };
}

export function isSnoozed(pullRequest: PullRequest): boolean {
    return pullRequest.snoozed_until !== null;
}

export function hasFailingChecks(pullRequest: PullRequest): boolean {
    return pullRequest.checks_state === 'FAILURE' || pullRequest.checks_state === 'ERROR';
}

function matchesSearch(pullRequest: PullRequest, search: string): boolean {
    const terms = search.toLowerCase().split(/\s+/).filter(Boolean);

    if (terms.length === 0) {
        return true;
    }

    const haystack = [
        pullRequest.title,
        `#${pullRequest.number}`,
        pullRequest.author_login,
        pullRequest.repository?.full_name,
        pullRequest.head_ref,
        pullRequest.base_ref,
        ...pullRequest.labels.map((label) => label.name),
    ]
        .filter(Boolean)
        .join('\n')
        .toLowerCase();

    return terms.every((term) => haystack.includes(term));
}

function matchesRequested(pullRequest: PullRequest, requested: RequestedFilter): boolean {
    if (requested === 'direct') {
        return pullRequest.is_review_requested_directly;
    }

    if (requested === 'team') {
        return pullRequest.requested_via_teams.length > 0;
    }

    if (requested.startsWith('team:')) {
        return pullRequest.requested_via_teams.includes(requested.slice('team:'.length));
    }

    return true;
}

export function pullRequestMatchesFilters(pullRequest: PullRequest, filters: PullRequestFilterState): boolean {
    if (filters.attention.length > 0 && !filters.attention.includes(pullRequest.attention)) {
        return false;
    }

    if (filters.repository !== '' && String(pullRequest.repository?.id) !== filters.repository) {
        return false;
    }

    if (!filters.showClosed && pullRequest.state !== 'open') {
        return false;
    }

    if (!filters.showSnoozed && isSnoozed(pullRequest)) {
        return false;
    }

    if (filters.hideDrafts && pullRequest.is_draft) {
        return false;
    }

    if (filters.onlyWithFindings && (pullRequest.open_findings_count ?? 0) === 0) {
        return false;
    }

    if (filters.onlyFailingChecks && !hasFailingChecks(pullRequest)) {
        return false;
    }

    return matchesRequested(pullRequest, filters.requested) && matchesSearch(pullRequest, filters.search);
}

function timestamp(iso: string | null): number {
    return iso ? new Date(iso).getTime() : 0;
}

function byRecentlyUpdated(a: PullRequest, b: PullRequest): number {
    return timestamp(b.updated_at) - timestamp(a.updated_at) || b.id - a.id;
}

const comparators: Record<PullRequestSort, (a: PullRequest, b: PullRequest) => number> = {
    updated: byRecentlyUpdated,
    oldest: (a, b) => timestamp(a.created_at) - timestamp(b.created_at) || a.id - b.id,
    created: (a, b) => timestamp(b.created_at) - timestamp(a.created_at) || b.id - a.id,
    largest: (a, b) => b.additions + b.deletions - (a.additions + a.deletions) || byRecentlyUpdated(a, b),
    findings: (a, b) =>
        (b.open_findings_count ?? 0) - (a.open_findings_count ?? 0) ||
        (b.unread_findings_count ?? 0) - (a.unread_findings_count ?? 0) ||
        byRecentlyUpdated(a, b),
    awaiting_first: (a, b) => Number(b.attention === 'awaiting') - Number(a.attention === 'awaiting') || byRecentlyUpdated(a, b),
};

export function sortPullRequests(pullRequests: PullRequest[], sort: PullRequestSort): PullRequest[] {
    return [...pullRequests].sort(comparators[sort] ?? byRecentlyUpdated);
}

export function summarisePullRequests(pullRequests: PullRequest[]): PullRequestStats {
    const byAttention: Record<ReviewAttention, number> = { awaiting: 0, approved: 0, changes_requested: 0, commented: 0, authored: 0, none: 0 };

    for (const pullRequest of pullRequests) {
        byAttention[pullRequest.attention]++;
    }

    return {
        awaiting: byAttention.awaiting,
        approved: byAttention.approved,
        changes_requested: byAttention.changes_requested,
        authored: byAttention.authored,
        via_teams: pullRequests.filter((pullRequest) => pullRequest.requested_via_teams.length > 0).length,
        approved_with_new_commits: pullRequests.filter(
            (pullRequest) => pullRequest.attention === 'approved' && pullRequest.has_commits_since_my_review,
        ).length,
        by_attention: byAttention,
    };
}

/** Number of narrowing filters in use (sorting and grouping don't count). */
export function countActiveFilters(filters: PullRequestFilterState): number {
    return [
        filters.search.trim() !== '',
        filters.attention.length > 0,
        filters.repository !== '',
        filters.requested !== 'any',
        filters.hideDrafts,
        filters.onlyWithFindings,
        filters.onlyFailingChecks,
        filters.showClosed,
        filters.showSnoozed,
    ].filter(Boolean).length;
}

/**
 * Client-side filtering and sorting for a list of pull requests, remembered per page in localStorage.
 */
export function usePullRequestFilters(storageKey: string, pullRequests: MaybeRefOrGetter<PullRequest[]>) {
    const filters = useStorage<PullRequestFilterState>(`prism:pull-request-filters:${storageKey}`, defaultPullRequestFilters(), localStorage, {
        mergeDefaults: true,
    });

    const visiblePullRequests = computed<PullRequest[]>(() =>
        sortPullRequests(
            toValue(pullRequests).filter((pullRequest) => pullRequestMatchesFilters(pullRequest, filters.value)),
            filters.value.sort,
        ),
    );

    const activeFilterCount = computed<number>(() => countActiveFilters(filters.value));

    function updateFilter<K extends keyof PullRequestFilterState>(key: K, value: PullRequestFilterState[K]): void {
        filters.value = { ...filters.value, [key]: value };
    }

    function clearFilters(): void {
        filters.value = { ...defaultPullRequestFilters(), sort: filters.value.sort, groupByRepository: filters.value.groupByRepository };
    }

    return { filters, visiblePullRequests, activeFilterCount, updateFilter, clearFilters };
}
