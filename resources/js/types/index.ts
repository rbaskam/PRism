import type { LucideIcon } from 'lucide-vue-next';

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
    badge?: number;
}

export interface GithubUser {
    login: string;
    name: string | null;
    avatar_url: string | null;
}

export interface SharedData {
    name: string;
    isNative: boolean;
    /** The web version is running inside Laravel Sail (Docker), so host CLIs and folders aren't available. */
    isContainer: boolean;
    github: GithubUser | null;
    claude: { configured: boolean };
    /** The app lock from Settings → Security. */
    appLock: { enabled: boolean };
    /** The running app version, e.g. "0.1.0". */
    appVersion: string;
    /** Set when a newer GitHub release than the running version exists. */
    update: AppRelease | null;
    /** Changelog for the version the user just updated to, until they dismiss it. */
    whatsNew: { version: string; notes: string } | null;
    counts: {
        awaiting_review: number;
        unread_inbox: number;
        active_reviews: number;
        /** Pull requests Claude is reviewing right now, oldest first. */
        reviewing: { pull_request_id: number; reference: string; title: string; status: 'queued' | 'running' }[];
    };
    flash: { success: string | null; error: string | null };
    errors: Record<string, string>;
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
    [key: string]: unknown;
}

export type BreadcrumbItemType = BreadcrumbItem;

/** Where a pull request stands from the connected user's point of view. */
export type ReviewAttention = 'awaiting' | 'approved' | 'changes_requested' | 'commented' | 'authored' | 'none';

export type AiTaskStatus = 'queued' | 'running' | 'completed' | 'failed';

export type FindingStatus = 'new' | 'read' | 'not_useful' | 'not_blocker';

export type FindingSeverity = 'critical' | 'high' | 'medium' | 'low';

export type FindingCategory = 'bug' | 'security' | 'duplication' | 'complexity' | 'tests' | 'performance' | 'other';

export type SubmitReviewEvent = 'APPROVE' | 'REQUEST_CHANGES' | 'COMMENT';

export type RequestedReviewer = { type: 'user'; login: string } | { type: 'team'; organization: string; slug: string; name: string };

export interface RepositorySummary {
    id: number;
    full_name: string;
    name: string;
    owner: string;
}

export interface Repository {
    id: number;
    github_id: number;
    owner: string;
    name: string;
    full_name: string;
    html_url: string;
    default_branch: string | null;
    is_private: boolean;
    is_tracked: boolean;
    local_path: string | null;
    synced_at: string | null;
    /** Present when the repository was loaded with pull request counts. */
    counts?: { open: number; awaiting: number; approved: number };
}

/** A repository the GitHub account can access, as returned by GET /github/repositories. */
export interface AvailableRepository {
    github_id: number;
    owner: string;
    name: string;
    full_name: string;
    html_url: string;
    default_branch: string | null;
    is_private: boolean;
    pushed_at: string | null;
    description: string | null;
    is_tracked: boolean;
    has_review_requests: boolean;
}

export interface ReviewRun {
    id: number;
    status: AiTaskStatus;
    is_active: boolean;
    head_sha: string | null;
    model: string | null;
    prompt: string;
    summary: string | null;
    verdict: 'approve' | 'request_changes' | 'comment' | null;
    new_findings_count: number;
    repeated_findings_count: number;
    resolved_findings_count: number;
    error: string | null;
    cost_usd: number | null;
    duration_ms: number | null;
    started_at: string | null;
    finished_at: string | null;
    created_at: string | null;
}

export interface PullRequest {
    id: number;
    repository?: RepositorySummary;
    number: number;
    title: string;
    html_url: string;
    state: 'open' | 'closed';
    is_draft: boolean;
    author_login: string | null;
    author_avatar_url: string | null;
    head_ref: string | null;
    base_ref: string | null;
    head_sha: string | null;
    additions: number;
    deletions: number;
    changed_files: number;
    comments_count: number;
    /** GitHub's overall decision: APPROVED, CHANGES_REQUESTED, REVIEW_REQUIRED or null. */
    review_decision: string | null;
    /** CI status rollup: SUCCESS, FAILURE, ERROR, PENDING, EXPECTED or null. */
    checks_state: string | null;
    /** MERGEABLE, CONFLICTING or UNKNOWN. */
    mergeable: string | null;
    attention: ReviewAttention;
    my_review_state: 'APPROVED' | 'CHANGES_REQUESTED' | 'COMMENTED' | 'DISMISSED' | null;
    my_review_submitted_at: string | null;
    has_commits_since_my_review: boolean;
    /** When a snooze ends; null when the pull request isn't snoozed (or the snooze has ended). */
    snoozed_until: string | null;
    is_review_requested_from_me: boolean;
    is_review_requested_directly: boolean;
    /** Teams ("org/slug") the user belongs to that were asked to review. */
    requested_via_teams: string[];
    requested_reviewers: RequestedReviewer[];
    labels: { name: string; color: string }[];
    is_authored_by_me: boolean;
    is_assigned_to_me: boolean;
    open_findings_count?: number;
    unread_findings_count?: number;
    latest_review_run?: ReviewRun | null;
    created_at: string | null;
    updated_at: string | null;
    closed_at: string | null;
    synced_at: string | null;
}

export interface ReviewFinding {
    id: number;
    review_run_id: number;
    last_seen_review_run_id: number | null;
    category: FindingCategory;
    category_label: string;
    severity: FindingSeverity;
    path: string | null;
    line: number | null;
    title: string;
    body: string;
    suggestion: string | null;
    status: FindingStatus;
    status_label: string;
    is_resolved: boolean;
    resolved_at: string | null;
    is_posted: boolean;
    posted_at: string | null;
    github_comment_url: string | null;
    /** The finding formatted as a GitHub comment, used to prefill the "post to PR" editor. */
    comment_body: string;
    created_at: string | null;
}

export interface GithubComment {
    node_id: string;
    database_id: number | null;
    author_login: string;
    author_avatar_url: string | null;
    body: string;
    /** HTML rendered and sanitised by GitHub. */
    body_html: string;
    url: string | null;
    created_at: string | null;
}

export interface ReviewThread {
    id: string;
    is_resolved: boolean;
    is_outdated: boolean;
    path: string;
    line: number | null;
    diff_hunk: string | null;
    comments: GithubComment[];
}

export interface SubmittedReview extends GithubComment {
    state: 'APPROVED' | 'CHANGES_REQUESTED' | 'COMMENTED' | 'DISMISSED' | 'PENDING';
}

export interface PullRequestConversation {
    error?: string;
    threads: ReviewThread[];
    comments: GithubComment[];
    reviews: SubmittedReview[];
}

export interface ReplyDraft {
    id: number;
    comment_node_id: string;
    status: AiTaskStatus;
    is_active: boolean;
    instructions: string | null;
    body: string | null;
    error: string | null;
    created_at: string | null;
}

export interface PullRequestPermissions {
    can_run_review: boolean;
    can_post_findings: boolean;
    can_reply_to_comments: boolean;
    can_approve: boolean;
    /** "Fix with Claude" is allowed in settings, Claude is connected and the repository has a local checkout. */
    can_change_code: boolean;
    /** Pushing Claude's changes is allowed in settings. */
    can_push: boolean;
    has_local_checkout: boolean;
}

export interface GithubNotification {
    id: number;
    /** e.g. review_requested, mention, team_mention, author, comment, assign, state_change, ci_activity */
    reason: string;
    subject_type: string;
    subject_title: string;
    html_url: string | null;
    repository_full_name: string;
    pull_request_id: number | null;
    is_unread: boolean;
    updated_at: string | null;
}

export interface ClaudeStatus {
    installed: boolean;
    binary: string | null;
    version: string | null;
    logged_in: boolean | null;
    auth_method: string | null;
    error: string | null;
}

export type ClaudeAuthMode = 'cli_login' | 'api_key' | 'oauth_token';

/** A file changed by a pull request, from GitHub's "list pull request files" API. */
export interface PullRequestFile {
    path: string;
    previous_path: string | null;
    status: 'added' | 'removed' | 'modified' | 'renamed' | 'copied' | 'changed' | 'unchanged';
    additions: number;
    deletions: number;
    /** Blob SHA of the file at the head commit; send it when marking the file viewed. */
    sha: string | null;
    /** Unified diff hunks. Null for binary files and diffs too large for GitHub to return. */
    patch: string | null;
    is_viewed: boolean;
    /** The user viewed an earlier version of this file. */
    changed_since_viewed: boolean;
}

export interface PullRequestFiles {
    error: string | null;
    files: PullRequestFile[];
}

/** A question about highlighted lines of the diff, answered by Claude. Follow-ups have parent_id = the thread's first question. */
export interface CodeQuestion {
    id: number;
    parent_id: number | null;
    path: string;
    /** RIGHT = new version (line numbers on the new side), LEFT = old version (removed lines). */
    side: 'RIGHT' | 'LEFT';
    start_line: number;
    end_line: number;
    /** e.g. "app/Foo.php:12-18" */
    location: string;
    selected_code: string;
    prompt: string;
    status: AiTaskStatus;
    is_active: boolean;
    /** GitHub-flavoured markdown written by Claude (plain text, not HTML). */
    answer: string | null;
    suggested_code: string | null;
    error: string | null;
    head_sha: string | null;
    model: string | null;
    cost_usd: number | null;
    duration_ms: number | null;
    /** When Claude started answering (null while queued). */
    started_at: string | null;
    created_at: string | null;
}

/** A PRism release on GitHub. */
export interface AppRelease {
    version: string;
    name: string;
    /** The release page on GitHub. */
    url: string;
    /** The installer for this computer (.dmg / .exe), or the release page when none matched. */
    download_url: string;
    /** Release notes in markdown. */
    notes: string;
    published_at: string | null;
}

/** A version section of CHANGELOG.md. notes is markdown (### Added, - item…). */
export interface ChangelogVersion {
    version: string;
    date: string | null;
    notes: string;
}

export type CodeChangeStatus = 'queued' | 'running' | 'ready' | 'no_changes' | 'pushing' | 'pushed' | 'discarded' | 'failed';

/** Changes Claude made to a pull request's code in a worktree ("Fix with Claude"). */
export interface CodeChange {
    id: number;
    status: CodeChangeStatus;
    is_active: boolean;
    /** pull_request_branch = push onto the PR's branch; new_pull_request = push a new branch and open a PR into the PR's branch. */
    target: 'pull_request_branch' | 'new_pull_request';
    destination_branch: string;
    instructions: string | null;
    finding_ids: number[];
    /** Review comments Claude was asked to address. */
    comments: { author: string; body: string; path?: string | null; line?: number | null }[];
    /** Claude's summary in markdown. */
    summary: string | null;
    commits: { sha: string; message: string }[];
    /** Unified diff of all commits (git diff base..HEAD), possibly several files. */
    diff: string | null;
    files_changed: number;
    additions: number;
    deletions: number;
    error: string | null;
    model: string | null;
    cost_usd: number | null;
    duration_ms: number | null;
    pushed_branch: string | null;
    created_pull_request_url: string | null;
    started_at: string | null;
    pushed_at: string | null;
    created_at: string | null;
}
