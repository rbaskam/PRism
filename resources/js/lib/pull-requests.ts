import type { FindingSeverity, FindingStatus, PullRequest, ReviewAttention } from '@/types';

export interface AttentionStyle {
    label: string;
    /** Badge variant from components/ui/badge. */
    badge: 'danger' | 'success' | 'warning' | 'info' | 'secondary' | 'muted';
    /** Tailwind classes for the coloured edge on list rows and cards. */
    border: string;
    /** Tailwind classes for a small status dot. */
    dot: string;
}

/**
 * Colours for where a pull request stands for the user: red while awaiting their review,
 * green once they approved it.
 */
export const attentionStyles: Record<ReviewAttention, AttentionStyle> = {
    awaiting: { label: 'Awaiting your review', badge: 'danger', border: 'border-l-red-500', dot: 'bg-red-500' },
    approved: { label: 'Approved by you', badge: 'success', border: 'border-l-emerald-500', dot: 'bg-emerald-500' },
    changes_requested: { label: 'You requested changes', badge: 'warning', border: 'border-l-amber-500', dot: 'bg-amber-500' },
    commented: { label: 'You commented', badge: 'info', border: 'border-l-sky-500', dot: 'bg-sky-500' },
    authored: { label: 'Your pull request', badge: 'secondary', border: 'border-l-violet-500', dot: 'bg-violet-500' },
    none: { label: 'Not involved', badge: 'muted', border: 'border-l-transparent', dot: 'bg-muted-foreground/40' },
};

export const severityStyles: Record<FindingSeverity, { label: string; badge: 'destructive' | 'danger' | 'warning' | 'info' | 'muted' }> = {
    critical: { label: 'Critical', badge: 'destructive' },
    high: { label: 'High', badge: 'danger' },
    medium: { label: 'Medium', badge: 'warning' },
    low: { label: 'Low', badge: 'info' },
};

export const findingStatusLabels: Record<FindingStatus, string> = {
    new: 'New',
    read: 'Read',
    not_useful: 'Not useful',
    not_blocker: 'Not a blocker',
};

export function checksLabel(state: string | null): { label: string; badge: 'success' | 'danger' | 'warning' | 'muted' } | null {
    switch (state) {
        case 'SUCCESS':
            return { label: 'Checks passing', badge: 'success' };
        case 'FAILURE':
        case 'ERROR':
            return { label: 'Checks failing', badge: 'danger' };
        case 'PENDING':
        case 'EXPECTED':
            return { label: 'Checks running', badge: 'warning' };
        default:
            return null;
    }
}

/** "Merged" or "Closed" for a pull request that is no longer open, with its Badge variant; null while it's open. */
export function closedStateLabel(pullRequest: Pick<PullRequest, 'state' | 'merged_at'>): { label: string; badge: 'merged' | 'muted' } | null {
    if (pullRequest.state === 'open') {
        return null;
    }

    return pullRequest.merged_at ? { label: 'Merged', badge: 'merged' } : { label: 'Closed', badge: 'muted' };
}

/** "owner/repo#123" */
export function pullRequestReference(pullRequest: PullRequest): string {
    return `${pullRequest.repository?.full_name ?? ''}#${pullRequest.number}`;
}

const relativeTime = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

const units: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 60 * 60 * 24 * 365],
    ['month', 60 * 60 * 24 * 30],
    ['week', 60 * 60 * 24 * 7],
    ['day', 60 * 60 * 24],
    ['hour', 60 * 60],
    ['minute', 60],
];

/** "3 hours ago", "yesterday", "just now" */
export function timeAgo(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }

    const seconds = (new Date(iso).getTime() - Date.now()) / 1000;

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return relativeTime.format(Math.round(seconds / size), unit);
        }
    }

    return 'just now';
}

/** "Mon 20 Oct, 14:05", for when a snooze ends. */
export function formatSnoozedUntil(iso: string): string {
    return new Date(iso).toLocaleString(undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

/** "2m 14s" */
export function formatDuration(milliseconds: number | null | undefined): string {
    if (milliseconds == null) {
        return '';
    }

    const totalSeconds = Math.round(milliseconds / 1000);
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;

    return minutes > 0 ? `${minutes}m ${seconds}s` : `${seconds}s`;
}
