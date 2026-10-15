<script setup lang="ts">
import ExternalLink from '@/components/ExternalLink.vue';
import AttentionBadge from '@/components/pull-requests/AttentionBadge.vue';
import SnoozeMenu from '@/components/pull-requests/SnoozeMenu.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { attentionStyles, checksLabel, formatSnoozedUntil, timeAgo } from '@/lib/pull-requests';
import { cn } from '@/lib/utils';
import type { PullRequest } from '@/types';
import { Link, router } from '@inertiajs/vue3';
import {
    AlarmClock,
    ArrowRight,
    CircleCheck,
    CircleDashed,
    CircleX,
    ExternalLink as ExternalLinkIcon,
    FileDiff,
    GitCommitHorizontal,
    GitMerge,
    LoaderCircle,
    MessageSquare,
    Sparkles,
    TriangleAlert,
    UserCheck,
    UserRound,
    Users,
} from 'lucide-vue-next';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        pullRequest: PullRequest;
        /** Hide the repository name when the list is already scoped to one repository. */
        showRepository?: boolean;
    }>(),
    {
        showRepository: true,
    },
);

const MAX_VISIBLE_LABELS = 3;

const numberFormat = new Intl.NumberFormat();

const style = computed(() => attentionStyles[props.pullRequest.attention]);
const checks = computed(() => checksLabel(props.pullRequest.checks_state));
const checksIcon = computed(() => {
    switch (checks.value?.badge) {
        case 'success':
            return CircleCheck;
        case 'danger':
            return CircleX;
        default:
            return CircleDashed;
    }
});
const isConflicting = computed(() => props.pullRequest.mergeable === 'CONFLICTING');
const reviewRun = computed(() => props.pullRequest.latest_review_run ?? null);
const openFindings = computed(() => props.pullRequest.open_findings_count ?? 0);
const unreadFindings = computed(() => props.pullRequest.unread_findings_count ?? 0);
const authorInitials = computed(() => (props.pullRequest.author_login ?? '?').slice(0, 2).toUpperCase());
const updatedAtTitle = computed(() =>
    props.pullRequest.updated_at ? `Updated ${new Date(props.pullRequest.updated_at).toLocaleString()}` : undefined,
);

/** GitHub sends label colours as hex without the leading "#". */
function labelColor(color: string | null | undefined): string | null {
    const hex = (color ?? '').replace(/^#/, '');

    return /^[0-9a-f]{6}$/i.test(hex) ? `#${hex}` : null;
}

const visibleLabels = computed(() =>
    props.pullRequest.labels.slice(0, MAX_VISIBLE_LABELS).map((label) => ({ name: label.name, color: labelColor(label.color) })),
);
const hiddenLabels = computed(() => props.pullRequest.labels.slice(MAX_VISIBLE_LABELS));

/**
 * The whole row opens the pull request, except clicks on links/buttons inside it or when the user is selecting text.
 */
function visitPullRequest(event: MouseEvent): void {
    const target = event.target instanceof Element ? event.target : null;

    if (event.defaultPrevented || target?.closest('a, button') || window.getSelection()?.toString()) {
        return;
    }

    router.visit(route('pull-requests.show', { pullRequest: props.pullRequest.id }));
}

const hasStatusBadges = computed(
    () =>
        props.pullRequest.attention !== 'none' ||
        props.pullRequest.snoozed_until !== null ||
        props.pullRequest.is_review_requested_directly ||
        props.pullRequest.requested_via_teams.length > 0 ||
        props.pullRequest.has_commits_since_my_review ||
        props.pullRequest.is_assigned_to_me ||
        openFindings.value > 0 ||
        reviewRun.value?.is_active === true ||
        reviewRun.value?.status === 'failed',
);
</script>

<template>
    <div
        :class="
            cn(
                'group flex cursor-pointer flex-col gap-x-4 gap-y-2 border-l-[3px] py-3 pl-3 pr-4 transition-colors focus-within:bg-accent/50 hover:bg-accent/50 sm:flex-row sm:pl-4',
                style.border,
                pullRequest.attention === 'awaiting' && !pullRequest.snoozed_until && 'bg-red-500/[0.03] dark:bg-red-500/[0.06]',
            )
        "
        @click="visitPullRequest"
    >
        <div class="flex min-w-0 flex-1 gap-3">
            <Avatar class="mt-0.5 h-8 w-8 text-[10px]">
                <AvatarImage v-if="pullRequest.author_avatar_url" :src="pullRequest.author_avatar_url" :alt="pullRequest.author_login ?? ''" />
                <AvatarFallback>{{ authorInitials }}</AvatarFallback>
            </Avatar>

            <div class="min-w-0 flex-1 space-y-1">
                <div class="flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground">
                    <span v-if="showRepository && pullRequest.repository" class="truncate">{{ pullRequest.repository.full_name }}</span>
                    <span class="shrink-0 tabular-nums">#{{ pullRequest.number }}</span>
                    <span aria-hidden="true">·</span>
                    <span class="shrink-0 truncate">{{ pullRequest.author_login ?? 'unknown' }}</span>
                    <template v-if="pullRequest.head_ref">
                        <span aria-hidden="true" class="hidden md:inline">·</span>
                        <span
                            class="hidden min-w-0 items-center gap-1 font-mono text-[11px] md:flex"
                            :title="`${pullRequest.head_ref} → ${pullRequest.base_ref ?? ''}`"
                        >
                            <span class="truncate">{{ pullRequest.head_ref }}</span>
                            <ArrowRight class="size-3 shrink-0" />
                            <span class="shrink-0">{{ pullRequest.base_ref }}</span>
                        </span>
                    </template>
                </div>

                <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                    <Link
                        :href="route('pull-requests.show', { pullRequest: pullRequest.id })"
                        class="max-w-full truncate rounded-sm text-sm font-medium text-foreground outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                        :title="pullRequest.title"
                    >
                        {{ pullRequest.title }}
                    </Link>
                    <Badge v-if="pullRequest.state !== 'open'" variant="muted" class="px-1.5 py-0 text-[11px]">Closed</Badge>
                    <Badge v-if="pullRequest.is_draft" variant="outline" class="px-1.5 py-0 text-[11px] text-muted-foreground">Draft</Badge>
                    <span
                        v-for="label in visibleLabels"
                        :key="label.name"
                        class="inline-flex max-w-40 items-center gap-1 rounded-full border px-1.5 text-[11px] leading-[18px] text-muted-foreground"
                        :style="label.color ? { borderColor: `${label.color}80` } : undefined"
                    >
                        <span
                            class="size-2 shrink-0 rounded-full bg-muted-foreground/40"
                            :style="label.color ? { backgroundColor: label.color } : undefined"
                        />
                        <span class="truncate">{{ label.name }}</span>
                    </span>
                    <span
                        v-if="hiddenLabels.length > 0"
                        class="text-[11px] text-muted-foreground"
                        :title="hiddenLabels.map((label) => label.name).join(', ')"
                    >
                        +{{ hiddenLabels.length }}
                    </span>
                </div>

                <div v-if="hasStatusBadges" class="flex flex-wrap items-center gap-1.5 pt-0.5">
                    <AttentionBadge v-if="pullRequest.attention !== 'none'" :attention="pullRequest.attention" />
                    <Badge v-if="pullRequest.snoozed_until" variant="muted" :title="`Snoozed until ${formatSnoozedUntil(pullRequest.snoozed_until)}`">
                        <AlarmClock />
                        Snoozed until {{ formatSnoozedUntil(pullRequest.snoozed_until) }}
                    </Badge>
                    <Badge v-if="pullRequest.is_review_requested_directly" variant="outline" title="Your review was requested by name">
                        <UserRound />
                        Requested from you
                    </Badge>
                    <Badge
                        v-for="team in pullRequest.requested_via_teams"
                        :key="team"
                        variant="outline"
                        :title="`Review requested from the @${team} team, which you are a member of`"
                    >
                        <Users />
                        via @{{ team }}
                    </Badge>
                    <Badge v-if="pullRequest.is_assigned_to_me" variant="outline">
                        <UserCheck />
                        Assigned to you
                    </Badge>
                    <Badge v-if="pullRequest.has_commits_since_my_review" variant="warning">
                        <GitCommitHorizontal />
                        New commits since your review
                    </Badge>
                    <Badge
                        v-if="openFindings > 0"
                        variant="muted"
                        class="bg-violet-100 text-violet-800 dark:bg-violet-500/15 dark:text-violet-300"
                        :title="`${openFindings} open AI finding${openFindings === 1 ? '' : 's'}${unreadFindings > 0 ? `, ${unreadFindings} unread` : ''}`"
                    >
                        <Sparkles />
                        {{ openFindings }} AI finding{{ openFindings === 1 ? '' : 's' }}
                        <span v-if="unreadFindings > 0" class="ml-0.5 rounded-sm bg-violet-600 px-1 text-[10px] font-semibold leading-4 text-white">
                            {{ unreadFindings }} new
                        </span>
                    </Badge>
                    <Badge
                        v-if="reviewRun?.is_active"
                        variant="outline"
                        class="border-violet-300 text-violet-700 dark:border-violet-500/40 dark:text-violet-300"
                    >
                        <LoaderCircle class="animate-spin" />
                        <span class="animate-pulse">{{ reviewRun.status === 'queued' ? 'AI review queued' : 'AI reviewing…' }}</span>
                    </Badge>
                    <Badge
                        v-else-if="reviewRun?.status === 'failed'"
                        variant="outline"
                        class="text-red-700 dark:text-red-400"
                        :title="reviewRun.error ?? undefined"
                    >
                        <TriangleAlert />
                        AI review failed
                    </Badge>
                </div>
            </div>
        </div>

        <div class="flex shrink-0 flex-wrap items-center gap-x-3 gap-y-1.5 pl-11 text-xs text-muted-foreground sm:flex-col sm:items-end sm:pl-0">
            <div class="flex items-center gap-2">
                <SnoozeMenu :pull-request="pullRequest" compact />
                <ExternalLink
                    :href="pullRequest.html_url"
                    class="rounded p-0.5 text-muted-foreground opacity-0 transition-opacity hover:text-foreground focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring group-hover:opacity-100"
                    title="Open on GitHub"
                >
                    <ExternalLinkIcon class="size-3.5" />
                    <span class="sr-only">Open on GitHub</span>
                </ExternalLink>
                <time :datetime="pullRequest.updated_at ?? undefined" :title="updatedAtTitle">{{ timeAgo(pullRequest.updated_at) }}</time>
            </div>

            <div class="flex items-center gap-2.5 tabular-nums">
                <span :title="`${numberFormat.format(pullRequest.additions)} additions, ${numberFormat.format(pullRequest.deletions)} deletions`">
                    <span class="text-emerald-600 dark:text-emerald-400">+{{ numberFormat.format(pullRequest.additions) }}</span>
                    <span class="ml-1 text-red-600 dark:text-red-400">−{{ numberFormat.format(pullRequest.deletions) }}</span>
                </span>
                <span
                    class="flex items-center gap-1"
                    :title="`${pullRequest.changed_files} file${pullRequest.changed_files === 1 ? '' : 's'} changed`"
                >
                    <FileDiff class="size-3.5" />
                    {{ pullRequest.changed_files }}
                </span>
                <span class="flex items-center gap-1" :title="`${pullRequest.comments_count} comment${pullRequest.comments_count === 1 ? '' : 's'}`">
                    <MessageSquare class="size-3.5" />
                    {{ pullRequest.comments_count }}
                </span>
            </div>

            <div v-if="checks || isConflicting" class="flex items-center gap-1.5">
                <Badge v-if="checks" :variant="checks.badge">
                    <component :is="checksIcon" />
                    {{ checks.label }}
                </Badge>
                <Badge v-if="isConflicting" variant="danger" title="This branch has merge conflicts">
                    <GitMerge />
                    Conflicts
                </Badge>
            </div>
        </div>
    </div>
</template>
