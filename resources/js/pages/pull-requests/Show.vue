<script setup lang="ts">
import type { RevealTarget } from '@/components/pull-request/code-questions';
import CodeChangesPanel from '@/components/pull-request/CodeChangesPanel.vue';
import ConversationPanel from '@/components/pull-request/ConversationPanel.vue';
import FilesChangedPanel from '@/components/pull-request/FilesChangedPanel.vue';
import FindingsPanel from '@/components/pull-request/FindingsPanel.vue';
import FixWithClaudeDialog from '@/components/pull-request/FixWithClaudeDialog.vue';
import GithubMarkdown from '@/components/pull-request/GithubMarkdown.vue';
import PostFindingDialog from '@/components/pull-request/PostFindingDialog.vue';
import PullRequestHeader from '@/components/pull-request/PullRequestHeader.vue';
import QuestionsPanel from '@/components/pull-request/QuestionsPanel.vue';
import ReviewRunHistory from '@/components/pull-request/ReviewRunHistory.vue';
import SubmitReviewDialog from '@/components/pull-request/SubmitReviewDialog.vue';
import { codeChangeStatusStyles, type CodeChangeDraft } from '@/components/pull-request/utils';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/AppLayout.vue';
import type {
    BreadcrumbItem,
    CodeChange,
    CodeQuestion,
    PullRequest,
    PullRequestConversation,
    PullRequestFiles,
    PullRequestPermissions,
    ReplyDraft,
    ReviewFinding,
    ReviewRun,
    SubmitReviewEvent,
} from '@/types';
import { Deferred, Head, router, usePage, usePoll } from '@inertiajs/vue3';
import { FileDiff, FileText, History, Loader2, MessageSquareCode, MessagesSquare, Sparkles, Wand2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    pullRequest: PullRequest;
    bodyHtml: string | null;
    findings: ReviewFinding[];
    reviewRuns: ReviewRun[];
    replyDrafts: ReplyDraft[];
    /** Questions about highlighted code, oldest first. */
    codeQuestions: CodeQuestion[];
    /** "Fix with Claude" attempts, latest first (discarded ones left out). */
    codeChanges: CodeChange[];
    reviewPrompt: string;
    permissions: PullRequestPermissions;
    /** Deferred: read live from GitHub after the page renders, which also refreshes the pull request. */
    conversation?: PullRequestConversation;
    /** Optional: the changed files and their diffs, read live from GitHub the first time the Files changed tab opens. */
    files?: PullRequestFiles;
}>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => {
    const repository = props.pullRequest.repository;
    const items: BreadcrumbItem[] = [{ title: 'Repositories', href: route('repositories.index') }];

    if (repository) {
        items.push({ title: repository.full_name, href: route('repositories.show', { repository: repository.id }) });
    }

    items.push({ title: `#${props.pullRequest.number}`, href: route('pull-requests.show', { pullRequest: props.pullRequest.id }) });

    return items;
});

const tabs = ['findings', 'files', 'questions', 'changes', 'conversation', 'description', 'history'];

/** Alerts link straight to a tab, e.g. ?tab=conversation for a new comment. */
const requestedTab = new URL(usePage().url, window.location.origin).searchParams.get('tab');
const activeTab = ref(requestedTab !== null && tabs.includes(requestedTab) ? requestedTab : 'findings');

/*
 * Diffs can be large, so they are only fetched from GitHub once the Files changed tab is opened. A full page reload
 * (e.g. after syncing with GitHub) leaves them out again, so they are fetched again while the tab is open.
 */
const loadingFiles = ref(false);

watch(
    [activeTab, () => props.files],
    ([tab, files]) => {
        if (tab !== 'files' || files || loadingFiles.value) {
            return;
        }

        loadingFiles.value = true;
        router.reload({ only: ['files'], onFinish: () => (loadingFiles.value = false) });
    },
    { immediate: true },
);

const openFindingsCount = computed(() => props.findings.filter((finding) => !finding.is_resolved).length);
const isReviewRunning = computed(() => props.reviewRuns.some((run) => run.is_active));
const isAnsweringQuestion = computed(() => props.codeQuestions.some((question) => question.is_active));
const isChangingCode = computed(() => props.codeChanges.some((change) => change.is_active));
const hasActiveWork = computed(
    () => isReviewRunning.value || isAnsweringQuestion.value || isChangingCode.value || props.replyDrafts.some((draft) => draft.is_active),
);

const latestCodeChange = computed(() => props.codeChanges[0] ?? null);
/** Show the latest change above the tabs while Claude works on it or it waits for review. */
const codeChangeNeedsAttention = computed(
    () => latestCodeChange.value !== null && (latestCodeChange.value.is_active || latestCodeChange.value.status === 'ready'),
);

const filesCount = computed(() => props.files?.files.length ?? props.pullRequest.changed_files);
const questionThreadCount = computed(() => props.codeQuestions.filter((question) => question.parent_id === null).length);

const conversationCount = computed(() =>
    props.conversation ? props.conversation.threads.length + props.conversation.comments.length + props.conversation.reviews.length : null,
);
const unresolvedThreadCount = computed(() => props.conversation?.threads.filter((thread) => !thread.is_resolved).length ?? 0);

/*
 * Poll while Claude is reviewing, drafting a reply, answering a question or changing code, so results show up without a refresh.
 */
const { start: startPolling, stop: stopPolling } = usePoll(
    3000,
    { only: ['reviewRuns', 'findings', 'pullRequest', 'replyDrafts', 'codeQuestions', 'codeChanges'] },
    { autoStart: false },
);

watch(hasActiveWork, (isActive) => (isActive ? startPolling() : stopPolling()), { immediate: true });

/*
 * Loading the conversation also re-reads the pull request from GitHub, so refresh the header once it arrives.
 */
watch(
    () => props.conversation,
    (conversation, previous) => {
        if (conversation && conversation !== previous) {
            router.reload({ only: ['pullRequest', 'bodyHtml', 'reviewPrompt'] });
        }
    },
);

/*
 * Once Claude's changes are pushed, the pull request on GitHub has new commits: re-read it, and the diff if it was loaded.
 */
watch(latestCodeChange, (latest, previous) => {
    if (latest && previous && latest.id === previous.id && previous.status === 'pushing' && latest.status === 'pushed') {
        router.reload({ only: ['conversation'] });

        if (props.files) {
            router.reload({ only: ['files'] });
        }
    }
});

/*
 * "Fix with Claude"
 */
const fixDialogOpen = ref(false);
const fixDraft = ref<CodeChangeDraft>({});

function openFixDialog(draft: CodeChangeDraft = {}): void {
    fixDraft.value = draft;
    fixDialogOpen.value = true;
}

function retryCodeChange(change: CodeChange): void {
    openFixDialog({ instructions: change.instructions, findingIds: change.finding_ids, target: change.target });
}

const reviewDialogOpen = ref(false);
const reviewEvent = ref<SubmitReviewEvent>('COMMENT');

function openReviewDialog(event: SubmitReviewEvent): void {
    reviewEvent.value = event;
    reviewDialogOpen.value = true;
}

/*
 * "Show in diff" from the Questions tab.
 */
const revealTarget = ref<RevealTarget | null>(null);

function showQuestionInDiff(question: CodeQuestion): void {
    activeTab.value = 'files';
    revealTarget.value = { path: question.path, side: question.side, line: question.end_line, nonce: Date.now() };
}

const postDialogOpen = ref(false);
const findingToPost = ref<ReviewFinding | null>(null);

function openPostDialog(finding: ReviewFinding): void {
    findingToPost.value = finding;
    postDialogOpen.value = true;
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`${pullRequest.title} · #${pullRequest.number}`" />

        <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 px-4 py-6 md:px-6">
            <PullRequestHeader
                :pull-request="pullRequest"
                :permissions="permissions"
                :review-prompt="reviewPrompt"
                :has-runs="reviewRuns.length > 0"
                :is-review-running="isReviewRunning"
                :is-changing-code="isChangingCode"
                @review="openReviewDialog"
                @fix="openFixDialog()"
            />

            <div
                v-if="codeChangeNeedsAttention && latestCodeChange && activeTab !== 'changes'"
                class="flex flex-wrap items-center gap-3 rounded-lg border border-violet-300 bg-violet-50 px-4 py-2.5 text-sm text-violet-950 dark:border-violet-500/30 dark:bg-violet-500/10 dark:text-violet-100"
                role="status"
            >
                <Loader2 v-if="latestCodeChange.is_active" class="size-4 shrink-0 animate-spin text-violet-500" />
                <Wand2 v-else class="size-4 shrink-0 text-violet-500" />
                <span class="font-medium">
                    <template v-if="latestCodeChange.status === 'pushing'">Pushing Claude's changes…</template>
                    <template v-else-if="latestCodeChange.is_active">Claude is changing the code…</template>
                    <template v-else>Claude's changes are ready to review</template>
                </span>
                <span v-if="latestCodeChange.status === 'ready'" class="font-mono text-xs opacity-75">
                    +{{ latestCodeChange.additions }} −{{ latestCodeChange.deletions }} in {{ latestCodeChange.files_changed }} file{{
                        latestCodeChange.files_changed === 1 ? '' : 's'
                    }}
                </span>
                <Button size="sm" variant="outline" class="ml-auto h-7 bg-transparent text-xs" @click="activeTab = 'changes'">
                    {{ latestCodeChange.status === 'ready' ? 'Review changes' : 'View progress' }}
                </Button>
            </div>

            <Tabs v-model="activeTab" class="min-w-0">
                <div class="-mx-4 overflow-x-auto px-4 md:mx-0 md:px-0">
                    <TabsList>
                        <TabsTrigger value="findings">
                            <Loader2 v-if="isReviewRunning" class="size-3.5 animate-spin text-violet-500" />
                            <Sparkles v-else class="size-3.5" />
                            AI findings
                            <span class="tabular-nums text-muted-foreground">({{ openFindingsCount }} open)</span>
                        </TabsTrigger>
                        <TabsTrigger value="files">
                            <FileDiff class="size-3.5" />
                            Files changed
                            <span class="tabular-nums text-muted-foreground">({{ filesCount }})</span>
                        </TabsTrigger>
                        <TabsTrigger value="questions">
                            <Loader2 v-if="isAnsweringQuestion" class="size-3.5 animate-spin text-violet-500" />
                            <MessageSquareCode v-else class="size-3.5" />
                            Questions
                            <span class="tabular-nums text-muted-foreground">({{ questionThreadCount }})</span>
                        </TabsTrigger>
                        <TabsTrigger value="changes">
                            <Loader2 v-if="isChangingCode" class="size-3.5 animate-spin text-violet-500" />
                            <Wand2 v-else class="size-3.5" />
                            Changes
                            <span class="tabular-nums text-muted-foreground">({{ codeChanges.length }})</span>
                            <span
                                v-if="latestCodeChange?.status === 'ready'"
                                class="size-1.5 rounded-full bg-amber-500"
                                :title="codeChangeStatusStyles.ready.label"
                            />
                        </TabsTrigger>
                        <TabsTrigger value="conversation">
                            <MessagesSquare class="size-3.5" />
                            Conversation
                            <Loader2 v-if="conversationCount === null" class="size-3 animate-spin text-muted-foreground" />
                            <span v-else class="tabular-nums text-muted-foreground">({{ conversationCount }})</span>
                            <span
                                v-if="unresolvedThreadCount > 0"
                                class="size-1.5 rounded-full bg-amber-500"
                                :title="`${unresolvedThreadCount} unresolved thread${unresolvedThreadCount === 1 ? '' : 's'}`"
                            />
                        </TabsTrigger>
                        <TabsTrigger value="description">
                            <FileText class="size-3.5" />
                            Description
                        </TabsTrigger>
                        <TabsTrigger value="history">
                            <History class="size-3.5" />
                            Review history
                            <span v-if="reviewRuns.length" class="tabular-nums text-muted-foreground">({{ reviewRuns.length }})</span>
                        </TabsTrigger>
                    </TabsList>
                </div>

                <TabsContent value="findings">
                    <FindingsPanel
                        :pull-request="pullRequest"
                        :findings="findings"
                        :review-runs="reviewRuns"
                        :permissions="permissions"
                        :review-prompt="reviewPrompt"
                        @post="openPostDialog"
                        @fix="(findingIds: number[]) => openFixDialog({ findingIds })"
                    />
                </TabsContent>

                <TabsContent value="files" force-mount>
                    <FilesChangedPanel
                        v-if="files"
                        :files="files"
                        :pull-request="pullRequest"
                        :code-questions="codeQuestions"
                        :permissions="permissions"
                        :is-active="activeTab === 'files'"
                        :reveal-target="revealTarget"
                    />
                    <template v-else>
                        <div class="space-y-3" aria-busy="true" aria-label="Loading the changed files from GitHub">
                            <div class="flex items-center gap-2 text-sm text-muted-foreground">
                                <Loader2 class="size-4 animate-spin" />
                                Loading the changed files from GitHub…
                            </div>
                            <Skeleton class="h-11 w-full rounded-lg" />
                            <div v-for="block in 3" :key="block" class="overflow-hidden rounded-lg border">
                                <div class="flex items-center gap-3 border-b px-3 py-2.5">
                                    <Skeleton class="h-3 w-64" />
                                    <Skeleton class="ml-auto h-5 w-16" />
                                </div>
                                <div class="space-y-2 p-3">
                                    <Skeleton v-for="row in 5" :key="row" class="h-3" :class="row % 2 ? 'w-3/4' : 'w-1/2'" />
                                </div>
                            </div>
                        </div>
                    </template>
                </TabsContent>

                <TabsContent value="questions">
                    <QuestionsPanel
                        :code-questions="codeQuestions"
                        :pull-request="pullRequest"
                        :can-ask="permissions.can_run_review"
                        :files="files?.files ?? null"
                        @show-in-diff="showQuestionInDiff"
                    />
                </TabsContent>

                <TabsContent value="changes">
                    <CodeChangesPanel
                        :code-changes="codeChanges"
                        :pull-request="pullRequest"
                        :permissions="permissions"
                        @fix="openFixDialog()"
                        @retry="retryCodeChange"
                    />
                </TabsContent>

                <TabsContent value="conversation" force-mount>
                    <Deferred data="conversation">
                        <template #fallback>
                            <div class="space-y-6" aria-busy="true" aria-label="Loading the conversation from GitHub">
                                <div class="flex items-center gap-2 text-sm text-muted-foreground">
                                    <Loader2 class="size-4 animate-spin" />
                                    Loading the conversation from GitHub…
                                </div>
                                <div v-for="block in 3" :key="block" class="space-y-3 rounded-lg border p-4">
                                    <div class="flex items-center gap-3">
                                        <Skeleton class="size-6 rounded-full" />
                                        <Skeleton class="h-3 w-32" />
                                        <Skeleton class="h-3 w-16" />
                                    </div>
                                    <Skeleton class="h-3 w-full" />
                                    <Skeleton class="h-3 w-4/5" />
                                    <Skeleton v-if="block === 2" class="h-20 w-full" />
                                </div>
                            </div>
                        </template>

                        <ConversationPanel
                            v-if="conversation"
                            :conversation="conversation"
                            :pull-request="pullRequest"
                            :permissions="permissions"
                            :reply-drafts="replyDrafts"
                        />
                    </Deferred>
                </TabsContent>

                <TabsContent value="description">
                    <div class="rounded-lg border bg-card px-5 py-4 text-card-foreground">
                        <GithubMarkdown v-if="bodyHtml" :html="bodyHtml" />
                        <p v-else class="text-sm italic text-muted-foreground">No description provided.</p>
                    </div>
                </TabsContent>

                <TabsContent value="history">
                    <ReviewRunHistory :review-runs="reviewRuns" />
                </TabsContent>
            </Tabs>
        </div>

        <SubmitReviewDialog
            v-model:open="reviewDialogOpen"
            :pull-request="pullRequest"
            :findings="findings"
            :permissions="permissions"
            :initial-event="reviewEvent"
        />

        <PostFindingDialog v-model:open="postDialogOpen" :finding="findingToPost" />

        <FixWithClaudeDialog
            v-model:open="fixDialogOpen"
            :pull-request="pullRequest"
            :findings="findings"
            :conversation="conversation"
            :permissions="permissions"
            :draft="fixDraft"
            @started="activeTab = 'changes'"
        />
    </AppLayout>
</template>
