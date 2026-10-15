<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { severityStyles } from '@/lib/pull-requests';
import { cn } from '@/lib/utils';
import type { FindingSeverity, FindingStatus, PullRequest, PullRequestPermissions, ReviewFinding, ReviewRun } from '@/types';
import { Link, router } from '@inertiajs/vue3';
import { Check, FlagOff, Keyboard, Loader2, RotateCcw, SearchX, Sparkles, ThumbsDown, Wand2, X } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';
import FindingItem from './FindingItem.vue';
import ReviewRunStatus from './ReviewRunStatus.vue';
import RunReviewButton from './RunReviewButton.vue';
import { pageVisit } from './utils';

/**
 * The AI findings for a pull request: the latest run's status, filters, bulk triage and the findings list.
 */
const props = defineProps<{
    pullRequest: PullRequest;
    findings: ReviewFinding[];
    reviewRuns: ReviewRun[];
    permissions: PullRequestPermissions;
    reviewPrompt: string;
}>();

const emit = defineEmits<{
    post: [finding: ReviewFinding];
    /** "Fix with Claude" for these findings. */
    fix: [findingIds: number[]];
}>();

type StatusFilter = 'open' | FindingStatus | 'resolved' | 'posted';
type SeverityFilter = 'all' | FindingSeverity;

const statusFilters: { value: StatusFilter; label: string; matches: (finding: ReviewFinding) => boolean }[] = [
    { value: 'open', label: 'All open', matches: (finding) => !finding.is_resolved },
    { value: 'new', label: 'New', matches: (finding) => !finding.is_resolved && finding.status === 'new' },
    { value: 'read', label: 'Read', matches: (finding) => !finding.is_resolved && finding.status === 'read' },
    { value: 'not_useful', label: 'Not useful', matches: (finding) => !finding.is_resolved && finding.status === 'not_useful' },
    { value: 'not_blocker', label: 'Not a blocker', matches: (finding) => !finding.is_resolved && finding.status === 'not_blocker' },
    { value: 'resolved', label: 'Resolved', matches: (finding) => finding.is_resolved },
    { value: 'posted', label: 'Posted', matches: (finding) => finding.is_posted },
];

const severities: FindingSeverity[] = ['critical', 'high', 'medium', 'low'];

const bulkActions: { status: FindingStatus; label: string; icon: typeof Check }[] = [
    { status: 'read', label: 'Mark read', icon: Check },
    { status: 'not_useful', label: 'Not useful', icon: ThumbsDown },
    { status: 'not_blocker', label: 'Not a blocker', icon: FlagOff },
    { status: 'new', label: 'Reset to new', icon: RotateCcw },
];

const statusFilter = ref<StatusFilter>('open');
const severityFilter = ref<SeverityFilter>('all');
const selectedIds = ref<number[]>([]);
const pendingIds = reactive(new Set<number>());
const bulkProcessing = ref(false);
const showShortcuts = ref(false);

const activeStatusFilter = computed(() => statusFilters.find((filter) => filter.value === statusFilter.value) ?? statusFilters[0]);

const statusCounts = computed(
    () =>
        Object.fromEntries(statusFilters.map((filter) => [filter.value, props.findings.filter(filter.matches).length])) as Record<
            StatusFilter,
            number
        >,
);

const statusMatches = computed(() => props.findings.filter(activeStatusFilter.value.matches));

const severityCounts = computed(
    () =>
        Object.fromEntries(
            severities.map((severity) => [severity, statusMatches.value.filter((finding) => finding.severity === severity).length]),
        ) as Record<FindingSeverity, number>,
);

const visibleFindings = computed(() =>
    severityFilter.value === 'all' ? statusMatches.value : statusMatches.value.filter((finding) => finding.severity === severityFilter.value),
);

const latestRun = computed(() => props.reviewRuns[0] ?? null);
const isReviewRunning = computed(() => latestRun.value?.is_active === true);

const selectedVisibleIds = computed(() => selectedIds.value.filter((id) => visibleFindings.value.some((finding) => finding.id === id)));

const selectAllState = computed<boolean | 'indeterminate'>(() => {
    if (selectedVisibleIds.value.length === 0) {
        return false;
    }

    return selectedVisibleIds.value.length === visibleFindings.value.length ? true : 'indeterminate';
});

/** Drop selected findings that are no longer on screen, so bulk actions only touch what the user sees. */
watch([visibleFindings], () => {
    if (selectedIds.value.length !== selectedVisibleIds.value.length) {
        selectedIds.value = selectedVisibleIds.value;
    }
});

function isSelected(id: number): boolean {
    return selectedIds.value.includes(id);
}

function setSelected(id: number, selected: boolean): void {
    selectedIds.value = selected ? [...new Set([...selectedIds.value, id])] : selectedIds.value.filter((selectedId) => selectedId !== id);
}

function toggleSelectAll(): void {
    selectedIds.value = selectAllState.value === true ? [] : visibleFindings.value.map((finding) => finding.id);
}

function updateStatus(finding: ReviewFinding, status: FindingStatus): void {
    pendingIds.add(finding.id);

    router.patch(route('findings.update', { finding: finding.id }), { status }, pageVisit({ onFinish: () => pendingIds.delete(finding.id) }));
}

function bulkUpdate(status: FindingStatus): void {
    if (selectedVisibleIds.value.length === 0) {
        return;
    }

    router.patch(
        route('pull-requests.findings.bulk-update', { pullRequest: props.pullRequest.id }),
        { finding_ids: selectedVisibleIds.value, status },
        pageVisit({
            onStart: () => (bulkProcessing.value = true),
            onFinish: () => (bulkProcessing.value = false),
            onSuccess: () => (selectedIds.value = []),
        }),
    );
}

function resetFilters(): void {
    statusFilter.value = 'open';
    severityFilter.value = 'all';
}

function chipClass(isActive: boolean): string {
    return cn(
        'inline-flex h-7 items-center gap-1.5 rounded-full border px-2.5 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-40',
        isActive
            ? 'border-foreground bg-foreground text-background'
            : 'bg-background text-muted-foreground hover:border-foreground/30 hover:text-foreground',
    );
}
</script>

<template>
    <div class="space-y-4">
        <ReviewRunStatus :pull-request="pullRequest" :review-runs="reviewRuns" :can-run-review="permissions.can_run_review" />

        <template v-if="findings.length > 0">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Filter by status">
                    <button
                        v-for="filter in statusFilters"
                        :key="filter.value"
                        type="button"
                        :class="chipClass(statusFilter === filter.value)"
                        :aria-pressed="statusFilter === filter.value"
                        @click="statusFilter = filter.value"
                    >
                        {{ filter.label }}
                        <span class="tabular-nums opacity-70">{{ statusCounts[filter.value] }}</span>
                    </button>
                </div>
                <div class="hidden h-5 w-px bg-border sm:block" />
                <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Filter by severity">
                    <button
                        type="button"
                        :class="chipClass(severityFilter === 'all')"
                        :aria-pressed="severityFilter === 'all'"
                        @click="severityFilter = 'all'"
                    >
                        Any severity
                    </button>
                    <button
                        v-for="severity in severities"
                        :key="severity"
                        type="button"
                        :class="chipClass(severityFilter === severity)"
                        :aria-pressed="severityFilter === severity"
                        :disabled="severityCounts[severity] === 0 && severityFilter !== severity"
                        @click="severityFilter = severityFilter === severity ? 'all' : severity"
                    >
                        {{ severityStyles[severity].label }}
                        <span class="tabular-nums opacity-70">{{ severityCounts[severity] }}</span>
                    </button>
                </div>
            </div>

            <div class="overflow-hidden rounded-lg border bg-card text-card-foreground">
                <div class="flex min-h-10 flex-wrap items-center gap-2 border-b bg-muted/40 px-3 py-1.5">
                    <Checkbox
                        class="size-4"
                        :checked="selectAllState"
                        :disabled="visibleFindings.length === 0"
                        aria-label="Select all visible findings"
                        @update:checked="toggleSelectAll"
                    />

                    <template v-if="selectedVisibleIds.length > 0">
                        <span class="mr-1 text-xs font-medium">{{ selectedVisibleIds.length }} selected</span>
                        <Button
                            v-for="action in bulkActions"
                            :key="action.status"
                            variant="outline"
                            size="sm"
                            class="h-7 px-2.5 text-xs [&_svg]:size-3.5"
                            :disabled="bulkProcessing"
                            @click="bulkUpdate(action.status)"
                        >
                            <component :is="action.icon" />
                            {{ action.label }}
                        </Button>
                        <Button
                            v-if="permissions.can_change_code"
                            variant="outline"
                            size="sm"
                            class="h-7 px-2.5 text-xs [&_svg]:size-3.5"
                            @click="emit('fix', selectedVisibleIds)"
                        >
                            <Wand2 />
                            Fix with Claude
                        </Button>
                        <Loader2 v-if="bulkProcessing" class="size-4 animate-spin text-muted-foreground" />
                        <Button variant="ghost" size="sm" class="ml-auto h-7 px-2 text-xs [&_svg]:size-3.5" @click="selectedIds = []">
                            <X />
                            Clear
                        </Button>
                    </template>

                    <template v-else>
                        <span class="text-xs text-muted-foreground">
                            {{ visibleFindings.length }} finding{{ visibleFindings.length === 1 ? '' : 's' }}
                        </span>
                        <button
                            type="button"
                            class="ml-auto inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                            :aria-expanded="showShortcuts"
                            @click="showShortcuts = !showShortcuts"
                        >
                            <Keyboard class="size-3.5" />
                            Shortcuts
                        </button>
                    </template>
                </div>

                <p v-if="showShortcuts" class="border-b bg-muted/20 px-3 py-2 text-xs text-muted-foreground">
                    Focus a finding, then: <kbd class="font-mono text-foreground">j</kbd>/<kbd class="font-mono text-foreground">k</kbd> move ·
                    <kbd class="font-mono text-foreground">Enter</kbd> expand · <kbd class="font-mono text-foreground">r</kbd> read ·
                    <kbd class="font-mono text-foreground">u</kbd> not useful · <kbd class="font-mono text-foreground">b</kbd> not a blocker ·
                    <kbd class="font-mono text-foreground">n</kbd> back to new · <kbd class="font-mono text-foreground">x</kbd> select ·
                    <kbd class="font-mono text-foreground">p</kbd> post to PR<template v-if="permissions.can_change_code">
                        · <kbd class="font-mono text-foreground">f</kbd> fix with Claude</template
                    >
                </p>

                <FindingItem
                    v-for="finding in visibleFindings"
                    :key="finding.id"
                    :finding="finding"
                    :selected="isSelected(finding.id)"
                    :busy="pendingIds.has(finding.id) || bulkProcessing"
                    :can-post-findings="permissions.can_post_findings"
                    :can-change-code="permissions.can_change_code"
                    @update:selected="(selected: boolean) => setSelected(finding.id, selected)"
                    @status="(status: FindingStatus) => updateStatus(finding, status)"
                    @post="emit('post', finding)"
                    @fix="emit('fix', [finding.id])"
                />

                <div v-if="visibleFindings.length === 0" class="flex flex-col items-center gap-2 px-6 py-10 text-center">
                    <SearchX class="size-6 text-muted-foreground/60" />
                    <p class="text-sm text-muted-foreground">No findings match these filters.</p>
                    <Button v-if="statusFilter !== 'open' || severityFilter !== 'all'" variant="outline" size="sm" @click="resetFilters">
                        Show all open findings
                    </Button>
                </div>
            </div>
        </template>

        <div v-else-if="isReviewRunning" class="rounded-lg border border-dashed px-6 py-10 text-center text-sm text-muted-foreground">
            Findings will appear here as soon as Claude finishes.
        </div>

        <div v-else class="flex flex-col items-center gap-3 rounded-lg border border-dashed px-6 py-12 text-center">
            <Sparkles class="size-8 text-violet-500/70" />
            <div class="space-y-1">
                <p class="text-sm font-medium">{{ reviewRuns.length > 0 ? 'Claude found nothing to flag' : 'No AI review yet' }}</p>
                <p class="max-w-md text-sm text-muted-foreground">
                    {{
                        reviewRuns.length > 0
                            ? 'Run the review again after new commits to re-check the changes.'
                            : 'Claude Code reviews the diff for bugs, security issues, duplication and missing tests. You decide what gets posted to GitHub.'
                    }}
                </p>
            </div>
            <RunReviewButton
                :pull-request-id="pullRequest.id"
                :review-prompt="reviewPrompt"
                :can-run-review="permissions.can_run_review"
                :has-runs="reviewRuns.length > 0"
                :is-running="isReviewRunning"
                size="default"
            />
            <Link
                v-if="!permissions.can_run_review"
                :href="route('settings.claude')"
                class="text-sm font-medium underline underline-offset-4 hover:no-underline"
            >
                Connect Claude in Settings
            </Link>
        </div>
    </div>
</template>
