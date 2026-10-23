<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { router } from '@inertiajs/vue3';
import { AlertTriangle, Loader2, MessageSquarePlus, Send } from 'lucide-vue-next';
import { computed, nextTick, onMounted, ref } from 'vue';
import type { QuestionTarget } from './code-questions';
import { formatLineRange } from './diff';
import SettingsHint from './SettingsHint.vue';
import { failedWithFlash, pageVisit, refreshConversation } from './utils';

/**
 * Writes a review comment on highlighted lines and posts it to the pull request on GitHub straight away.
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
const processing = ref(false);
const errors = ref<Record<string, string>>({});

const firstError = computed(() => errors.value.body ?? Object.values(errors.value)[0]);
const canSubmit = computed(() => props.canComment && !props.spansHunks && !processing.value && body.value.trim() !== '');

onMounted(() => {
    container.value?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });

    nextTick(() => {
        const textarea = container.value?.querySelector('textarea');
        textarea?.focus({ preventScroll: true });
        textarea?.setSelectionRange(textarea.value.length, textarea.value.length);
    });
});

function submit(): void {
    if (!canSubmit.value) {
        return;
    }

    router.post(
        route('pull-requests.comments.store', { pullRequest: props.pullRequestId }),
        {
            body: body.value.trim(),
            path: props.target.path,
            side: props.target.side,
            start_line: props.target.startLine,
            line: props.target.endLine,
        },
        pageVisit({
            onStart: () => {
                processing.value = true;
                errors.value = {};
            },
            onFinish: () => (processing.value = false),
            onError: (validationErrors) => (errors.value = validationErrors),
            onSuccess: (page) => {
                if (failedWithFlash(page)) {
                    return;
                }

                body.value = '';
                emit('submitted');
                refreshConversation();
            },
        }),
    );
}

function handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        submit();
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

        <form v-else class="space-y-2" @submit.prevent="submit">
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
            <div class="flex items-center gap-2">
                <span class="hidden text-xs text-muted-foreground sm:inline">Markdown supported · ⌘/Ctrl + Enter to comment · Esc to cancel</span>
                <div class="ml-auto flex items-center gap-2">
                    <Button type="button" variant="ghost" size="sm" class="h-8" @click="emit('cancel')">Cancel</Button>
                    <Button type="submit" size="sm" class="h-8" :disabled="!canSubmit">
                        <Loader2 v-if="processing" class="animate-spin" />
                        <Send v-else />
                        Comment
                    </Button>
                </div>
            </div>
        </form>
    </div>
</template>
