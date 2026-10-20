<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { CodeQuestion, PullRequest, PullRequestFile } from '@/types';
import { Link } from '@inertiajs/vue3';
import { FileCode2, MessageSquareCode } from 'lucide-vue-next';
import { computed } from 'vue';
import CodeQuestionThread from './CodeQuestionThread.vue';
import { groupQuestionThreads, threadsByPath } from './code-questions';

/**
 * Every question asked about the code of this pull request, grouped by file.
 */
const props = defineProps<{
    codeQuestions: CodeQuestion[];
    pullRequest: PullRequest;
    canAsk: boolean;
    /** The changed files, once loaded, to flag questions on files that left the pull request. */
    files: PullRequestFile[] | null;
}>();

const emit = defineEmits<{
    'show-in-diff': [question: CodeQuestion];
}>();

const groups = computed(() =>
    Array.from(threadsByPath(groupQuestionThreads(props.codeQuestions)), ([path, threads]) => ({
        path,
        threads: [...threads].sort((a, b) => a.root.start_line - b.root.start_line || a.root.id - b.root.id),
        isInPullRequest: props.files === null || props.files.some((file) => file.path === path),
    })).sort((a, b) => a.path.localeCompare(b.path)),
);
</script>

<template>
    <div>
        <div v-if="groups.length === 0" class="flex flex-col items-center gap-2 rounded-lg border border-dashed px-6 py-10 text-center">
            <MessageSquareCode class="size-8 text-muted-foreground/60" />
            <p class="text-sm font-medium">No questions yet</p>
            <p class="max-w-md text-sm text-muted-foreground">
                In Files changed, click a line number (shift-click for a range) or select some code, then press
                <kbd class="rounded border px-1 font-mono text-xs">a</kbd> to ask Claude about it. Answers are saved here.
            </p>
            <Link v-if="!canAsk" :href="route('settings.claude')" class="text-sm font-medium underline underline-offset-4"
                >Connect Claude in Settings</Link
            >
        </div>

        <div v-else class="space-y-6">
            <section v-for="group in groups" :key="group.path" class="space-y-2">
                <div class="flex items-center gap-2">
                    <FileCode2 class="size-4 shrink-0 text-muted-foreground" />
                    <span class="min-w-0 truncate font-mono text-xs font-medium" :title="group.path">{{ group.path }}</span>
                    <span class="shrink-0 text-xs text-muted-foreground">
                        {{ group.threads.length }} thread{{ group.threads.length === 1 ? '' : 's' }}
                    </span>
                    <Badge v-if="!group.isInPullRequest" variant="muted" class="px-1.5 text-[10px]">no longer in this pull request</Badge>
                </div>
                <div class="space-y-2">
                    <CodeQuestionThread
                        v-for="thread in group.threads"
                        :key="thread.root.id"
                        :thread="thread"
                        :pull-request="pullRequest"
                        :can-ask="canAsk"
                        :default-open="false"
                        show-location
                        :can-show-in-diff="group.isInPullRequest"
                        @show-in-diff="emit('show-in-diff', thread.root)"
                    />
                </div>
            </section>
        </div>
    </div>
</template>
