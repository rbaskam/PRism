<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { router } from '@inertiajs/vue3';
import { AlertTriangle, Clock, Lightbulb, Loader2, MessageSquarePlus, Send } from 'lucide-vue-next';
import { computed, nextTick, onMounted, ref } from 'vue';
import type { QuestionTarget } from './code-questions';
import { formatLineRange } from './diff';
import SettingsHint from './SettingsHint.vue';
import { failedWithFlash, pageVisit, refreshConversation } from './utils';

/**
 * Writes a review comment on highlighted lines. "Comment" posts it to GitHub straight away; "Add to review" keeps it
 * in the app until the review is submitted, when it's posted with the review. A suggested change can be inserted
 * for lines on the new version, which GitHub shows with an "Apply suggestion" button.
 */
const props = defineProps<{
    pullRequestId: number;
    target: QuestionTarget;
    /** Commenting is allowed in Settings → PR review. */
    canComment: boolean;
    /** The selection covers more than one hunk, which GitHub can't attach a comment to. */
    spansHunks: boolean;
}>();

const emit = defineEmits<{
    cancel: [];
    submitted: [];
}>();

const body = defineModel<string>('body', { default: '' });

const container = ref<HTMLElement | null>(null);
const processing = ref<'comment' | 'review' | null>(null);
const errors = ref<Record<string, string>>({});

const firstError = computed(() => errors.value.body ?? Object.values(errors.value)[0]);
const canSubmit = computed(() => props.canComment && !props.spansHunks && processing.value === null && body.value.trim() !== '');

/** The selected lines as they read in the new version, to start a suggested change from. Only for added and unchanged lines. */
const suggestionCode = computed(() =>
    props.target.side === 'RIGHT'
        ? props.target.selectedCode
              .split('\n')
              .filter((row) => !row.startsWith('-'))
              .map((row) => row.slice(1))
              .join('\n')
        : null,
);

function textarea(): HTMLTextAreaElement | null {
    return container.value?.querySelector('textarea') ?? null;
}

/** Inserts a ```suggestion block with the selected code at the cursor, ready to edit. */
function insertSuggestion(): void {
    if (suggestionCode.value === null) {
        return;
    }

    const field = textarea();
    const start = field?.selectionStart ?? body.value.length;
    const end = field?.selectionEnd ?? start;
    const before = body.value.slice(0, start);
    const opening = `${before === '' || before.endsWith('\n') ? '' : '\n'}\`\`\`suggestion\n`;
    const block = `${opening}${suggestionCode.value}\n\`\`\`\n`;

    body.value = before + block + body.value.slice(end);

    nextTick(() => {
        const cursor = start + opening.length + suggestionCode.value!.length;
        field?.focus();
        field?.setSelectionRange(cursor, cursor);
    });
}

onMounted(() => {
    container.value?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });

    nextTick(() => {
        const textarea = container.value?.querySelector('textarea');
        textarea?.focus({ preventScroll: true });
        textarea?.setSelectionRange(textarea.value.length, textarea.value.length);
    });
});

function submit(mode: 'comment' | 'review' = 'comment'): void {
    if (!canSubmit.value) {
        return;
    }

    router.post(
        route(mode === 'review' ? 'pull-requests.pending-comments.store' : 'pull-requests.comments.store', { pullRequest: props.pullRequestId }),
        {
            body: body.value.trim(),
            path: props.target.path,
            side: props.target.side,
            start_line: props.target.startLine,
            line: props.target.endLine,
        },
        pageVisit({
            onStart: () => {
                processing.value = mode;
                errors.value = {};
            },
            onFinish: () => (processing.value = null),
            onError: (validationErrors) => (errors.value = validationErrors),
            onSuccess: (page) => {
                if (failedWithFlash(page)) {
                    return;
                }

                body.value = '';
                emit('submitted');

                if (mode === 'comment') {
                    refreshConversation();
                }
            },
        }),
    );
}

function handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        submit(event.shiftKey ? 'review' : 'comment');
    }

    if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        emit('cancel');
    }
}
</script>

<template>
    <div ref="container" class="space-y-2.5">
        <div class="flex items-center gap-2 text-xs text-muted-foreground">
            <MessageSquarePlus class="size-3.5" />
            <span>
                Comment on
                <span class="font-medium text-foreground">{{ formatLineRange(target.startLine, target.endLine) }}</span>
                {{ target.side === 'LEFT' ? '(removed code)' : '' }}
            </span>
        </div>

        <div v-if="!canComment" class="flex items-center gap-2">
            <SettingsHint message="Commenting on GitHub is off" />
            <Button type="button" variant="ghost" size="sm" class="ml-auto h-8" @click="emit('cancel')">Cancel</Button>
        </div>

        <form v-else class="space-y-2" @submit.prevent="submit('comment')">
            <p
                v-if="spansHunks"
                class="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
                role="alert"
            >
                <AlertTriangle class="mt-px size-3.5 shrink-0" />
                GitHub only accepts a comment on lines from one part of the diff. Select lines between two @@ headers.
            </p>
            <Textarea
                v-model="body"
                placeholder="Leave a comment on GitHub…"
                class="min-h-20 text-sm"
                aria-label="Comment"
                @keydown="handleKeydown"
            />
            <InputError :message="firstError" />
            <div class="flex flex-wrap items-center gap-2">
                <Button
                    v-if="suggestionCode !== null && !spansHunks"
                    type="button"
                    variant="outline"
                    size="sm"
                    class="h-8"
                    title="Insert the selected lines as a suggested change that can be applied on GitHub"
                    @click="insertSuggestion"
                >
                    <Lightbulb />
                    Suggest a change
                </Button>
                <span class="hidden text-xs text-muted-foreground xl:inline">
                    ⌘/Ctrl + Enter to comment · add Shift to add to your review · Esc to cancel
                </span>
                <div class="ml-auto flex items-center gap-2">
                    <Button type="button" variant="ghost" size="sm" class="h-8" @click="emit('cancel')">Cancel</Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="h-8"
                        :disabled="!canSubmit"
                        title="Keep it with your review and post it when you submit the review"
                        @click="submit('review')"
                    >
                        <Loader2 v-if="processing === 'review'" class="animate-spin" />
                        <Clock v-else />
                        Add to review
                    </Button>
                    <Button type="submit" size="sm" class="h-8" :disabled="!canSubmit" title="Post it to GitHub now">
                        <Loader2 v-if="processing === 'comment'" class="animate-spin" />
                        <Send v-else />
                        Comment now
                    </Button>
                </div>
            </div>
        </form>
    </div>
</template>
