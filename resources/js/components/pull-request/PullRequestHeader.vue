<script setup lang="ts">
import ExternalLink from '@/components/ExternalLink.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { attentionStyles, checksLabel, timeAgo } from '@/lib/pull-requests';
import type { PullRequest, PullRequestPermissions, SharedData, SubmitReviewEvent } from '@/types';
import { usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowRight,
    Check,
    ExternalLink as ExternalLinkIcon,
    FileDiff,
    GitPullRequestDraft,
    MessageSquare,
    XCircle,
} from 'lucide-vue-next';
import { computed } from 'vue';
import FixWithClaudeButton from './FixWithClaudeButton.vue';
import GithubAvatar from './GithubAvatar.vue';
import RunReviewButton from './RunReviewButton.vue';
import { formatDateTime } from './utils';

const props = defineProps<{
    pullRequest: PullRequest;
    permissions: PullRequestPermissions;
    reviewPrompt: string;
    hasRuns: boolean;
    isReviewRunning: boolean;
    /** Claude is changing or pushing code for this pull request ("Fix with Claude"). */
    isChangingCode: boolean;
}>();

const emit = defineEmits<{
    review: [event: SubmitReviewEvent];
    fix: [];
}>();

const page = usePage<SharedData>();

const attention = computed(() => attentionStyles[props.pullRequest.attention]);
const checks = computed(() => checksLabel(props.pullRequest.checks_state));
const hasConflicts = computed(() => props.pullRequest.mergeable === 'CONFLICTING');

const myReviewLabel: Record<NonNullable<PullRequest['my_review_state']>, string> = {
    APPROVED: 'approved',
    CHANGES_REQUESTED: 'requested changes',
    COMMENTED: 'commented',
    DISMISSED: 'reviewed (dismissed)',
};

const newCommitsMessage = computed(() => {
    const since = props.pullRequest.my_review_submitted_at ? ` (${timeAgo(props.pullRequest.my_review_submitted_at)})` : '';

    return `New commits were pushed since your last review${since}. Take another look before it merges.`;
});

interface ReviewerChip {
    key: string;
    label: string;
    title: string;
    isMe: boolean;
}

const requestedReviewers = computed<ReviewerChip[]>(() => {
    const myLogin = page.props.github?.login?.toLowerCase();
    const myTeams = props.pullRequest.requested_via_teams.map((team) => team.toLowerCase());

    return props.pullRequest.requested_reviewers.map((reviewer) => {
        if (reviewer.type === 'user') {
            const isMe = reviewer.login.toLowerCase() === myLogin;

            return { key: `user:${reviewer.login}`, label: isMe ? 'you' : `@${reviewer.login}`, title: `@${reviewer.login}`, isMe };
        }

        const handle = `${reviewer.organization}/${reviewer.slug}`;
        const isMe = myTeams.includes(handle.toLowerCase());

        return { key: `team:${handle}`, label: isMe ? `you via @${handle}` : `@${handle}`, title: reviewer.name, isMe };
    });
});
</script>

<template>
    <header class="flex flex-col gap-3">
        <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
            <div class="min-w-0 flex-1 space-y-2">
                <div class="flex flex-wrap items-center gap-1.5">
                    <Badge :variant="attention.badge">
                        <span class="size-1.5 rounded-full" :class="attention.dot" />
                        {{ attention.label }}
                    </Badge>
                    <Badge v-if="pullRequest.state === 'closed'" variant="secondary">Closed</Badge>
                    <Badge v-if="pullRequest.is_draft" variant="muted">
                        <GitPullRequestDraft />
                        Draft
                    </Badge>
                    <Badge v-if="checks" :variant="checks.badge">{{ checks.label }}</Badge>
                    <Badge v-if="hasConflicts" variant="danger">
                        <AlertTriangle />
                        Merge conflicts
                    </Badge>
                    <span
                        v-for="label in pullRequest.labels"
                        :key="label.name"
                        class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium leading-4"
                        :style="{ borderColor: `#${label.color}80`, backgroundColor: `#${label.color}26` }"
                    >
                        {{ label.name }}
                    </span>
                </div>

                <h1 class="text-xl font-semibold leading-snug tracking-tight">
                    {{ pullRequest.title }}
                    <span class="font-normal text-muted-foreground">#{{ pullRequest.number }}</span>
                </h1>

                <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs text-muted-foreground">
                    <span v-if="pullRequest.author_login" class="inline-flex items-center gap-1.5">
                        <GithubAvatar :login="pullRequest.author_login" :url="pullRequest.author_avatar_url" class="size-5 text-[9px]" />
                        <span class="font-medium text-foreground">{{ pullRequest.author_login }}</span>
                    </span>
                    <span v-if="pullRequest.head_ref" class="inline-flex min-w-0 items-center gap-1 font-mono">
                        <span class="truncate rounded bg-muted px-1.5 py-0.5 text-foreground">{{ pullRequest.head_ref }}</span>
                        <ArrowRight class="size-3 shrink-0" />
                        <span class="truncate rounded bg-muted px-1.5 py-0.5 text-foreground">{{ pullRequest.base_ref }}</span>
                    </span>
                    <span class="inline-flex items-center gap-1.5 font-mono tabular-nums">
                        <span class="text-emerald-600 dark:text-emerald-400">+{{ pullRequest.additions.toLocaleString() }}</span>
                        <span class="text-red-600 dark:text-red-400">−{{ pullRequest.deletions.toLocaleString() }}</span>
                    </span>
                    <span class="inline-flex items-center gap-1">
                        <FileDiff class="size-3.5" />
                        {{ pullRequest.changed_files }} file{{ pullRequest.changed_files === 1 ? '' : 's' }}
                    </span>
                    <span v-if="pullRequest.updated_at" :title="formatDateTime(pullRequest.updated_at)"
                        >Updated {{ timeAgo(pullRequest.updated_at) }}</span
                    >
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Button variant="outline" size="sm" as-child>
                    <ExternalLink :href="pullRequest.html_url">
                        <ExternalLinkIcon />
                        Open on GitHub
                    </ExternalLink>
                </Button>
                <RunReviewButton
                    :pull-request-id="pullRequest.id"
                    :review-prompt="reviewPrompt"
                    :can-run-review="permissions.can_run_review"
                    :has-runs="hasRuns"
                    :is-running="isReviewRunning"
                />
                <FixWithClaudeButton :permissions="permissions" :is-working="isChangingCode" @click="emit('fix')" />
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 border-t pt-3">
            <div class="flex min-w-0 flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                <template v-if="requestedReviewers.length">
                    <span>Review requested from</span>
                    <Badge
                        v-for="reviewer in requestedReviewers"
                        :key="reviewer.key"
                        :variant="reviewer.isMe ? 'danger' : 'outline'"
                        :title="reviewer.title"
                        class="font-mono font-normal"
                    >
                        {{ reviewer.label }}
                    </Badge>
                </template>
                <span v-else>No pending review requests</span>
                <span v-if="pullRequest.my_review_state" class="before:mx-1.5 before:content-['·']">
                    You {{ myReviewLabel[pullRequest.my_review_state] }}
                    <span v-if="pullRequest.my_review_submitted_at" :title="formatDateTime(pullRequest.my_review_submitted_at)">
                        {{ timeAgo(pullRequest.my_review_submitted_at) }}
                    </span>
                </span>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Tooltip :disabled="permissions.can_approve">
                    <TooltipTrigger as-child>
                        <span class="inline-flex" :tabindex="permissions.can_approve ? undefined : 0">
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="!permissions.can_approve"
                                class="border-emerald-600/40 text-emerald-700 hover:bg-emerald-50 hover:text-emerald-800 dark:border-emerald-500/40 dark:text-emerald-400 dark:hover:bg-emerald-500/10 dark:hover:text-emerald-300"
                                @click="emit('review', 'APPROVE')"
                            >
                                <Check />
                                Approve
                            </Button>
                        </span>
                    </TooltipTrigger>
                    <TooltipContent class="max-w-xs text-xs">GitHub doesn't let you approve your own pull request.</TooltipContent>
                </Tooltip>
                <Tooltip :disabled="permissions.can_approve">
                    <TooltipTrigger as-child>
                        <span class="inline-flex" :tabindex="permissions.can_approve ? undefined : 0">
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="!permissions.can_approve"
                                class="border-red-600/40 text-red-700 hover:bg-red-50 hover:text-red-800 dark:border-red-500/40 dark:text-red-400 dark:hover:bg-red-500/10 dark:hover:text-red-300"
                                @click="emit('review', 'REQUEST_CHANGES')"
                            >
                                <XCircle />
                                Request changes
                            </Button>
                        </span>
                    </TooltipTrigger>
                    <TooltipContent class="max-w-xs text-xs">GitHub doesn't let you request changes on your own pull request.</TooltipContent>
                </Tooltip>
                <Button variant="outline" size="sm" @click="emit('review', 'COMMENT')">
                    <MessageSquare />
                    Comment
                </Button>
            </div>
        </div>

        <div
            v-if="pullRequest.has_commits_since_my_review"
            class="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
        >
            <AlertTriangle class="mt-0.5 size-4 shrink-0" />
            <p>{{ newCommitsMessage }}</p>
        </div>
    </header>
</template>
