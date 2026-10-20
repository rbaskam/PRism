<script setup lang="ts">
import { Badge, type BadgeVariants } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useExternalLink } from '@/composables/useExternalLink';
import AppLayout from '@/layouts/AppLayout.vue';
import { timeAgo } from '@/lib/pull-requests';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem, GithubNotification } from '@/types';
import { Head, router, usePoll } from '@inertiajs/vue3';
import {
    Bell,
    Check,
    CheckCheck,
    CircleCheck,
    CircleDot,
    ExternalLink as ExternalLinkIcon,
    GitCommitHorizontal,
    GitPullRequest,
    Inbox,
    Loader2,
    MessagesSquare,
    Search,
    ShieldAlert,
    Tag,
} from 'lucide-vue-next';
import { computed, ref, type Component } from 'vue';

const props = defineProps<{
    notifications: GithubNotification[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Inbox', href: route('inbox.index') }];

usePoll(60000, { only: ['notifications'] });

const { openExternal } = useExternalLink();

type BadgeVariant = NonNullable<BadgeVariants['variant']>;

/** Friendly labels for GitHub's notification reasons, in the order the filter chips are shown. */
const reasonStyles: Record<string, { label: string; badge: BadgeVariant }> = {
    review_requested: { label: 'Review requested', badge: 'danger' },
    mention: { label: 'Mentioned', badge: 'warning' },
    team_mention: { label: 'Team mentioned', badge: 'warning' },
    assign: { label: 'Assigned', badge: 'info' },
    author: { label: 'Your PR', badge: 'secondary' },
    comment: { label: 'Comment', badge: 'outline' },
    ci_activity: { label: 'CI', badge: 'muted' },
    state_change: { label: 'State changed', badge: 'muted' },
    approval_requested: { label: 'Approval requested', badge: 'warning' },
    security_alert: { label: 'Security alert', badge: 'danger' },
    invitation: { label: 'Invitation', badge: 'info' },
    subscribed: { label: 'Watching', badge: 'muted' },
    manual: { label: 'Subscribed', badge: 'muted' },
};

const reasonOrder = Object.keys(reasonStyles);

function reasonStyle(reason: string): { label: string; badge: BadgeVariant } {
    const label = reason.replace(/_/g, ' ');

    return reasonStyles[reason] ?? { label: label.charAt(0).toUpperCase() + label.slice(1), badge: 'muted' };
}

const subjectIcons: Record<string, Component> = {
    PullRequest: GitPullRequest,
    Issue: CircleDot,
    Release: Tag,
    Discussion: MessagesSquare,
    Commit: GitCommitHorizontal,
    CheckSuite: CircleCheck,
    RepositoryVulnerabilityAlert: ShieldAlert,
    RepositoryDependabotAlertsThread: ShieldAlert,
};

const subjectLabels: Record<string, string> = {
    PullRequest: 'Pull request',
    Issue: 'Issue',
    Release: 'Release',
    Discussion: 'Discussion',
    Commit: 'Commit',
    CheckSuite: 'CI run',
};

/** Notifications marked as read while the request is in flight, so the row updates instantly. */
const markingAsRead = ref<Set<number>>(new Set());
const markingAll = ref(false);

function isUnread(notification: GithubNotification): boolean {
    return notification.is_unread && !markingAsRead.value.has(notification.id) && !markingAll.value;
}

const unreadCount = computed(() => props.notifications.filter(isUnread).length);

type ReadFilter = 'unread' | 'all';

const readFilter = ref<ReadFilter>(props.notifications.some((notification) => notification.is_unread) ? 'unread' : 'all');
const reasonFilter = ref<string | null>(null);

const readFilterOptions = computed<{ value: ReadFilter; label: string; count: number }[]>(() => [
    { value: 'unread', label: 'Unread', count: unreadCount.value },
    { value: 'all', label: 'All', count: props.notifications.length },
]);
const search = ref<string | number>('');

const searchTerms = computed(() => String(search.value).trim().toLowerCase().split(/\s+/).filter(Boolean));

const readFiltered = computed(() => (readFilter.value === 'unread' ? props.notifications.filter(isUnread) : props.notifications));

const reasonCounts = computed(() => {
    const counts = new Map<string, number>();

    for (const notification of readFiltered.value) {
        counts.set(notification.reason, (counts.get(notification.reason) ?? 0) + 1);
    }

    if (reasonFilter.value && !counts.has(reasonFilter.value)) {
        counts.set(reasonFilter.value, 0);
    }

    const rank = (reason: string): number => (reasonOrder.includes(reason) ? reasonOrder.indexOf(reason) : reasonOrder.length);

    return [...counts.entries()].map(([reason, count]) => ({ reason, count })).sort((first, second) => rank(first.reason) - rank(second.reason));
});

const visibleNotifications = computed(() =>
    readFiltered.value.filter((notification) => {
        if (reasonFilter.value && notification.reason !== reasonFilter.value) {
            return false;
        }

        const haystack = `${notification.subject_title} ${notification.repository_full_name}`.toLowerCase();

        return searchTerms.value.every((term) => haystack.includes(term));
    }),
);

const hasExtraFilters = computed(() => reasonFilter.value !== null || searchTerms.value.length > 0);

function toggleReason(reason: string): void {
    reasonFilter.value = reasonFilter.value === reason ? null : reason;
}

function clearFilters(): void {
    reasonFilter.value = null;
    search.value = '';
}

/** Pull requests open in the app, fetched from GitHub first if they haven't synced yet; anything else opens on github.com. */
function open(notification: GithubNotification): void {
    if (notification.pull_request_id) {
        router.visit(route('pull-requests.show', { pullRequest: notification.pull_request_id }));

        return;
    }

    if (notification.subject_type === 'PullRequest') {
        router.visit(route('inbox.open', { notification: notification.id }));

        return;
    }

    openExternal(notification.html_url);
}

function markAsRead(notification: GithubNotification): void {
    router.post(
        route('inbox.read', { notification: notification.id }),
        {},
        {
            preserveScroll: true,
            onStart: () => (markingAsRead.value = new Set(markingAsRead.value).add(notification.id)),
            onFinish: () => {
                const remaining = new Set(markingAsRead.value);
                remaining.delete(notification.id);
                markingAsRead.value = remaining;
            },
        },
    );
}

function markAllAsRead(): void {
    router.post(
        route('inbox.read-all'),
        {},
        {
            preserveScroll: true,
            onStart: () => (markingAll.value = true),
            onFinish: () => (markingAll.value = false),
        },
    );
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Inbox" />

        <template #header-actions>
            <Button variant="outline" size="sm" :disabled="unreadCount === 0 || markingAll" @click="markAllAsRead">
                <Loader2 v-if="markingAll" class="animate-spin" />
                <CheckCheck v-else />
                Mark all as read
            </Button>
        </template>

        <div class="flex flex-col gap-4 p-4 md:p-6">
            <div class="space-y-0.5">
                <h1 class="text-xl font-semibold tracking-tight">Inbox</h1>
                <p class="text-sm text-muted-foreground">
                    GitHub notifications: review requests, mentions and activity on your pull requests. Checked every minute.
                </p>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <div class="inline-flex shrink-0 rounded-lg border bg-muted/40 p-0.5" role="group" aria-label="Show">
                    <button
                        v-for="option in readFilterOptions"
                        :key="option.value"
                        type="button"
                        :aria-pressed="readFilter === option.value"
                        :class="
                            cn(
                                'inline-flex items-center gap-1.5 rounded-md px-3 py-1 text-sm font-medium transition-colors',
                                readFilter === option.value
                                    ? 'bg-background text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground',
                            )
                        "
                        @click="readFilter = option.value"
                    >
                        {{ option.label }}
                        <span class="text-xs tabular-nums text-muted-foreground">{{ option.count }}</span>
                    </button>
                </div>

                <div class="relative flex-1 sm:max-w-sm">
                    <Search class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        v-model="search"
                        type="search"
                        placeholder="Search titles and repositories…"
                        class="h-9 pl-8"
                        aria-label="Search notifications"
                    />
                </div>
            </div>

            <div v-if="reasonCounts.length > 0" class="flex flex-wrap items-center gap-1.5">
                <button
                    type="button"
                    :aria-pressed="reasonFilter === null"
                    :class="
                        cn(
                            'rounded-full border px-2.5 py-0.5 text-xs font-medium transition-colors',
                            reasonFilter === null
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                        )
                    "
                    @click="reasonFilter = null"
                >
                    All reasons
                </button>
                <button
                    v-for="{ reason, count } in reasonCounts"
                    :key="reason"
                    type="button"
                    :aria-pressed="reasonFilter === reason"
                    :class="
                        cn(
                            'inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-medium transition-colors',
                            reasonFilter === reason
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                        )
                    "
                    @click="toggleReason(reason)"
                >
                    {{ reasonStyle(reason).label }}
                    <span class="tabular-nums opacity-70">{{ count }}</span>
                </button>
            </div>

            <div v-if="notifications.length === 0" class="flex flex-col items-center gap-3 rounded-lg border border-dashed px-6 py-16 text-center">
                <Inbox class="size-8 text-muted-foreground" />
                <div class="space-y-1">
                    <p class="font-medium">No notifications yet</p>
                    <p class="max-w-md text-sm text-muted-foreground">
                        Review requests, mentions and activity on your pull requests show up here. {{ $page.props.name }} checks GitHub every minute.
                    </p>
                </div>
            </div>

            <div
                v-else-if="visibleNotifications.length === 0 && readFilter === 'unread' && !hasExtraFilters"
                class="flex flex-col items-center gap-3 rounded-lg border border-dashed px-6 py-16 text-center"
            >
                <CheckCheck class="size-8 text-emerald-600 dark:text-emerald-500" />
                <div class="space-y-1">
                    <p class="font-medium">You're all caught up</p>
                    <p class="text-sm text-muted-foreground">No unread notifications.</p>
                </div>
                <Button variant="outline" size="sm" @click="readFilter = 'all'">Show all notifications</Button>
            </div>

            <div
                v-else-if="visibleNotifications.length === 0"
                class="flex flex-col items-center gap-3 rounded-lg border border-dashed px-6 py-12 text-center"
            >
                <p class="text-sm text-muted-foreground">No notifications match your filters.</p>
                <Button variant="ghost" size="sm" @click="clearFilters">Clear filters</Button>
            </div>

            <ul v-else class="divide-y overflow-hidden rounded-lg border">
                <li
                    v-for="notification in visibleNotifications"
                    :key="notification.id"
                    :class="
                        cn(
                            'group flex items-center gap-1 pr-2 transition-colors hover:bg-muted/50',
                            isUnread(notification) && 'bg-sky-50/40 dark:bg-sky-500/[0.04]',
                        )
                    "
                >
                    <button
                        type="button"
                        class="flex min-w-0 flex-1 items-start gap-3 py-3 pl-3 pr-2 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
                        @click="open(notification)"
                    >
                        <span
                            :class="cn('mt-1.5 size-2 shrink-0 rounded-full', isUnread(notification) ? 'bg-sky-500' : 'bg-transparent')"
                            :aria-label="isUnread(notification) ? 'Unread' : undefined"
                        />
                        <component
                            :is="subjectIcons[notification.subject_type] ?? Bell"
                            :class="cn('mt-0.5 size-4 shrink-0', isUnread(notification) ? 'text-foreground' : 'text-muted-foreground')"
                            :aria-label="subjectLabels[notification.subject_type] ?? notification.subject_type"
                        />
                        <div class="min-w-0 flex-1 space-y-1">
                            <p :class="cn('truncate text-sm', isUnread(notification) ? 'font-semibold' : 'text-muted-foreground')">
                                {{ notification.subject_title }}
                            </p>
                            <div class="flex min-w-0 items-center gap-2 text-xs text-muted-foreground">
                                <Badge :variant="reasonStyle(notification.reason).badge" class="shrink-0">{{
                                    reasonStyle(notification.reason).label
                                }}</Badge>
                                <span class="truncate">{{ notification.repository_full_name }}</span>
                            </div>
                        </div>
                        <time
                            v-if="notification.updated_at"
                            :datetime="notification.updated_at"
                            :title="new Date(notification.updated_at).toLocaleString()"
                            class="shrink-0 pt-0.5 text-xs text-muted-foreground"
                        >
                            {{ timeAgo(notification.updated_at) }}
                        </time>
                    </button>

                    <div class="flex shrink-0 items-center gap-0.5 sm:opacity-0 sm:focus-within:opacity-100 sm:group-hover:opacity-100">
                        <Button
                            v-if="isUnread(notification)"
                            variant="ghost"
                            size="icon"
                            class="size-8"
                            title="Mark as read"
                            @click="markAsRead(notification)"
                        >
                            <Check />
                            <span class="sr-only">Mark as read</span>
                        </Button>
                        <Button
                            v-if="notification.html_url"
                            variant="ghost"
                            size="icon"
                            class="size-8"
                            title="Open on GitHub"
                            @click="openExternal(notification.html_url)"
                        >
                            <ExternalLinkIcon />
                            <span class="sr-only">Open on GitHub</span>
                        </Button>
                    </div>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
