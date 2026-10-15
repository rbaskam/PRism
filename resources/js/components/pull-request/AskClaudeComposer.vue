<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Link, router } from '@inertiajs/vue3';
import { Loader2, Sparkles } from 'lucide-vue-next';
import { computed, nextTick, onMounted, ref } from 'vue';
import { questionStarters, type QuestionTarget } from './code-questions';
import { formatLineRange } from './diff';
import DiffHunk from './DiffHunk.vue';
import { failedWithFlash, pageVisit } from './utils';

/**
 * Asks Claude about highlighted lines, or a follow-up in an existing question thread (with parentId).
 */
const props = withDefaults(
    defineProps<{
        pullRequestId: number;
        target: QuestionTarget;
        parentId?: number | null;
        canAsk: boolean;
        variant?: 'question' | 'follow-up';
    }>(),
    {
        parentId: null,
        variant: 'question',
    },
);

const emit = defineEmits<{
    cancel: [];
    submitted: [];
}>();

const prompt = defineModel<string>('prompt', { default: '' });

const container = ref<HTMLElement | null>(null);
const processing = ref(false);
const errors = ref<Record<string, string>>({});

const isFollowUp = computed(() => props.variant === 'follow-up');
const firstError = computed(() => errors.value.prompt ?? Object.values(errors.value)[0]);

function focusPrompt(): void {
    nextTick(() => {
        const textarea = container.value?.querySelector('textarea');
        textarea?.focus({ preventScroll: true });
        textarea?.setSelectionRange(textarea.value.length, textarea.value.length);
    });
}

onMounted(() => {
    if (!isFollowUp.value) {
        container.value?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        focusPrompt();
    }
});

function applyStarter(starter: string): void {
    prompt.value = starter;
    focusPrompt();
}

function submit(): void {
    if (!props.canAsk || processing.value || prompt.value.trim() === '') {
        return;
    }

    router.post(
        route('pull-requests.code-questions.store', { pullRequest: props.pullRequestId }),
        {
            path: props.target.path,
            side: props.target.side,
            start_line: props.target.startLine,
            end_line: props.target.endLine,
            selected_code: props.target.selectedCode,
            prompt: prompt.value.trim(),
            ...(props.parentId ? { parent_id: props.parentId } : {}),
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

                prompt.value = '';
                emit('submitted');
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
        <template v-if="!isFollowUp">
            <div class="flex items-center gap-2 text-xs text-muted-foreground">
                <Sparkles class="size-3.5 text-violet-500" />
                <span>
                    Ask Claude about
                    <span class="font-medium text-foreground">{{ formatLineRange(target.startLine, target.endLine) }}</span>
                    {{ target.side === 'LEFT' ? '(removed code)' : '' }}
                </span>
            </div>
            <DiffHunk :hunk="target.selectedCode" :visible-lines="10" />
        </template>

        <p v-if="!canAsk" class="text-sm text-muted-foreground">
            Claude isn't connected.
            <Link :href="route('settings.claude')" class="font-medium text-foreground underline underline-offset-2">Connect Claude in Settings</Link>
            to ask about code.
        </p>

        <template v-else>
            <div v-if="!isFollowUp" class="flex flex-wrap gap-1.5">
                <button
                    v-for="starter in questionStarters"
                    :key="starter"
                    type="button"
                    class="rounded-full border bg-background px-2.5 py-0.5 text-xs text-muted-foreground transition-colors hover:border-foreground/30 hover:text-foreground"
                    @click="applyStarter(starter)"
                >
                    {{ starter }}
                </button>
            </div>

            <form class="space-y-2" @submit.prevent="submit">
                <Textarea
                    v-model="prompt"
                    :placeholder="isFollowUp ? 'Ask a follow-up…' : 'What would you like to know about these lines?'"
                    :class="isFollowUp ? 'min-h-16 text-sm' : 'min-h-20 text-sm'"
                    aria-label="Question for Claude"
                    @keydown="handleKeydown"
                />
                <InputError :message="firstError" />
                <div class="flex items-center gap-2">
                    <span class="hidden text-xs text-muted-foreground sm:inline">⌘/Ctrl + Enter to ask · Esc to cancel</span>
                    <div class="ml-auto flex items-center gap-2">
                        <Button type="button" variant="ghost" size="sm" class="h-8" @click="emit('cancel')">Cancel</Button>
                        <Button type="submit" size="sm" class="h-8" :disabled="processing || prompt.trim() === ''">
                            <Loader2 v-if="processing" class="animate-spin" />
                            <Sparkles v-else />
                            {{ isFollowUp ? 'Ask follow-up' : 'Ask Claude' }}
                        </Button>
                    </div>
                </div>
            </form>
        </template>
    </div>
</template>
