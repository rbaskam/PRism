import type { AiTaskStatus, CodeChangeStatus, PullRequestPermissions, ReviewFinding, ReviewRun, SharedData, SubmittedReview } from '@/types';
import type { Page, VisitOptions } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';

/** The comment an AI reply draft is linked to, and the discussion Claude gets as context. */
export interface DraftTarget {
    /** A review thread's last comment, or the last pull request conversation comment. */
    nodeId: string;
    databaseId: number | null;
    type: 'review_thread' | 'conversation';
    /** The discussion so far, oldest first. */
    context: { author: string; body: string }[];
    path?: string | null;
    diffHunk?: string | null;
}

type BadgeVariant = 'default' | 'secondary' | 'outline' | 'success' | 'danger' | 'warning' | 'info' | 'muted';

/**
 * Options for actions on the pull request page. The conversation and changed files are read live from GitHub,
 * so they are left out of the reload that follows an action (no skeleton, no extra API calls) and refreshed on their own.
 */
export function pageVisit(options: VisitOptions = {}): VisitOptions {
    return { preserveScroll: true, except: ['conversation', 'files'], ...options };
}

/** Whether the redirect back carried an error flash, e.g. GitHub rejected the request. */
export function failedWithFlash(page: Page): boolean {
    return Boolean((page.props as unknown as SharedData).flash?.error);
}

/** Re-reads the conversation from GitHub while keeping the current one on screen. */
export function refreshConversation(): void {
    router.reload({ only: ['conversation'] });
}

/** Queues an AI review of the pull request. */
export function startReviewRun(pullRequestId: number, options: VisitOptions = {}): void {
    router.post(route('pull-requests.review-runs.store', { pullRequest: pullRequestId }), {}, pageVisit(options));
}

export const runStatusStyles: Record<AiTaskStatus, { label: string; badge: BadgeVariant }> = {
    queued: { label: 'Queued', badge: 'muted' },
    running: { label: 'Reviewing', badge: 'info' },
    completed: { label: 'Completed', badge: 'success' },
    failed: { label: 'Failed', badge: 'danger' },
};

export const verdictStyles: Record<NonNullable<ReviewRun['verdict']>, { label: string; badge: BadgeVariant }> = {
    approve: { label: 'Claude suggests approving', badge: 'success' },
    request_changes: { label: 'Claude suggests changes', badge: 'danger' },
    comment: { label: 'Claude has comments', badge: 'info' },
};

export const reviewStateStyles: Record<SubmittedReview['state'], { label: string; badge: BadgeVariant }> = {
    APPROVED: { label: 'Approved', badge: 'success' },
    CHANGES_REQUESTED: { label: 'Changes requested', badge: 'danger' },
    COMMENTED: { label: 'Commented', badge: 'info' },
    DISMISSED: { label: 'Dismissed', badge: 'muted' },
    PENDING: { label: 'Pending', badge: 'muted' },
};

/** "$0.42" */
export function formatCost(cost: number | null | undefined): string {
    if (cost == null) {
        return '';
    }

    const value = Number(cost);

    return value > 0 && value < 0.01 ? '<$0.01' : `$${value.toFixed(2)}`;
}

/** "app/Models/User.php:42", or null for general findings. */
export function findingLocation(finding: Pick<ReviewFinding, 'path' | 'line'>): string | null {
    if (!finding.path) {
        return null;
    }

    return finding.line ? `${finding.path}:${finding.line}` : finding.path;
}

export function shortSha(sha: string | null | undefined): string {
    return sha ? sha.slice(0, 7) : '';
}

/** Full local date and time, for title tooltips next to relative times. */
export function formatDateTime(iso: string | null | undefined): string {
    return iso ? new Date(iso).toLocaleString() : '';
}

/** When a run started waiting or working, for the live elapsed timer. */
export function runStartedAt(run: ReviewRun): string | null {
    return run.status === 'running' ? (run.started_at ?? run.created_at) : run.created_at;
}

export const codeChangeStatusStyles: Record<CodeChangeStatus, { label: string; badge: BadgeVariant }> = {
    queued: { label: 'Queued', badge: 'muted' },
    running: { label: 'Changing code', badge: 'info' },
    ready: { label: 'Ready to review', badge: 'warning' },
    no_changes: { label: 'No changes', badge: 'muted' },
    pushing: { label: 'Pushing', badge: 'info' },
    pushed: { label: 'Pushed', badge: 'success' },
    discarded: { label: 'Discarded', badge: 'muted' },
    failed: { label: 'Failed', badge: 'danger' },
};

/** A setting to fix before an action is available, e.g. "Connect Claude" → Settings → Claude. */
export interface SettingsBlocker {
    label: string;
    href: string;
}

/** What stops "Fix with Claude" from being used, each with the settings page that fixes it. */
export function codeChangeBlockers(permissions: PullRequestPermissions): SettingsBlocker[] {
    const blockers: SettingsBlocker[] = [];

    if (!permissions.can_run_review) {
        blockers.push({ label: 'Connect Claude in Settings → Claude', href: route('settings.claude') });
    }

    if (!permissions.has_local_checkout) {
        blockers.push({ label: 'Link a local checkout in Settings → GitHub', href: route('settings.github') });
    }

    if (!permissions.can_change_code && blockers.length === 0) {
        blockers.push({ label: 'Turn on code changes in Settings → PR review', href: route('settings.review') });
    }

    return blockers;
}

/** What the "Fix with Claude" dialog starts with, e.g. a finding to fix or a failed attempt to try again. */
export interface CodeChangeDraft {
    instructions?: string | null;
    findingIds?: number[];
    target?: 'pull_request_branch' | 'new_pull_request';
}
