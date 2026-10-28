<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { Repository, RepositoryReviewPrompt } from '@/types';
import { Link, useForm } from '@inertiajs/vue3';
import { Loader2, RotateCcw } from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';

/**
 * Edits the prompt Claude reviews a repository's pull requests with. Without one, reviews use the app-wide prompt
 * from Settings → PR review.
 */
const props = defineProps<{
    repository: Repository;
    reviewPrompt: RepositoryReviewPrompt;
}>();

const open = defineModel<boolean>('open', { required: true });

const form = useForm({ review_prompt: '' });
const clearForm = useForm({ review_prompt: null as string | null });

const promptTextarea = ref<InstanceType<typeof Textarea> | null>(null);

const hasCustomPrompt = computed(() => props.reviewPrompt.custom !== null);
const error = computed(() => form.errors.review_prompt ?? clearForm.errors.review_prompt);
const canSave = computed(() => form.isDirty && form.review_prompt.trim() !== '' && !form.processing && !clearForm.processing);

watch(
    open,
    (isOpen) => {
        if (!isOpen) {
            return;
        }

        form.review_prompt = props.reviewPrompt.custom ?? props.reviewPrompt.app_wide;
        form.defaults();
        form.clearErrors();
        clearForm.clearErrors();
    },
    { immediate: true },
);

function textareaElement(): HTMLTextAreaElement | undefined {
    return promptTextarea.value?.$el as HTMLTextAreaElement | undefined;
}

function focusPrompt(): void {
    nextTick(() => textareaElement()?.focus());
}

function insertPlaceholder(placeholder: string): void {
    const textarea = textareaElement();
    const start = textarea?.selectionStart ?? form.review_prompt.length;
    const end = textarea?.selectionEnd ?? start;

    form.review_prompt = form.review_prompt.slice(0, start) + placeholder + form.review_prompt.slice(end);

    nextTick(() => {
        textarea?.focus();
        textarea?.setSelectionRange(start + placeholder.length, start + placeholder.length);
    });
}

function save(): void {
    if (!canSave.value) {
        return;
    }

    form.patch(route('repositories.update', { repository: props.repository.id }), {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
        onError: focusPrompt,
    });
}

function useAppWidePrompt(): void {
    clearForm.patch(route('repositories.update', { repository: props.repository.id }), {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
        onError: focusPrompt,
    });
}

function handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        save();
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] max-w-2xl overflow-y-auto">
            <DialogHeader>
                <DialogTitle class="flex flex-wrap items-center gap-2 pr-6">
                    <span class="min-w-0 break-words">Review prompt for {{ repository.full_name }}</span>
                    <Badge :variant="hasCustomPrompt ? 'info' : 'muted'">{{ hasCustomPrompt ? 'Custom' : 'App-wide' }}</Badge>
                </DialogTitle>
                <DialogDescription>
                    What Claude is asked to do when it reviews pull requests in this repository.
                    {{
                        hasCustomPrompt
                            ? 'This repository uses its own prompt instead of the app-wide one.'
                            : 'It uses the app-wide prompt. Edit it below to give this repository its own.'
                    }}
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="save">
                <div class="grid gap-2">
                    <Label for="repository-review-prompt" class="sr-only">Review prompt</Label>
                    <Textarea
                        id="repository-review-prompt"
                        ref="promptTextarea"
                        v-model="form.review_prompt"
                        rows="10"
                        spellcheck="false"
                        :maxlength="reviewPrompt.max_length"
                        :aria-invalid="Boolean(error)"
                        aria-describedby="repository-review-prompt-length"
                        class="min-h-56 resize-y font-mono text-[13px] leading-relaxed md:text-[13px]"
                        @keydown="handleKeydown"
                    />
                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                        <span id="repository-review-prompt-length" class="tabular-nums">
                            {{ form.review_prompt.length.toLocaleString() }} / {{ reviewPrompt.max_length.toLocaleString() }} characters
                        </span>
                        <Link
                            :href="route('settings.review')"
                            class="underline underline-offset-2 hover:text-foreground focus-visible:rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            Edit the app-wide prompt in Settings → PR review
                        </Link>
                    </div>
                    <InputError :message="error" />
                </div>

                <div class="space-y-2">
                    <p class="text-sm font-medium">
                        Placeholders <span class="font-normal text-muted-foreground">· click to insert at the cursor</span>
                    </p>
                    <div class="grid gap-1.5 sm:grid-cols-2">
                        <button
                            v-for="(description, placeholder) in reviewPrompt.placeholders"
                            :key="placeholder"
                            type="button"
                            :class="
                                cn(
                                    'flex items-center gap-2 rounded-md border px-2.5 py-1.5 text-left text-xs transition-colors hover:bg-muted',
                                    form.review_prompt.includes(placeholder) && 'border-primary/40',
                                )
                            "
                            @click="insertPlaceholder(placeholder)"
                        >
                            <code class="shrink-0 font-mono font-medium">{{ placeholder }}</code>
                            <span class="truncate text-muted-foreground">{{ description }}</span>
                        </button>
                    </div>
                </div>

                <DialogFooter class="flex-wrap gap-2 sm:justify-between">
                    <Button
                        v-if="hasCustomPrompt"
                        type="button"
                        variant="ghost"
                        :disabled="clearForm.processing || form.processing"
                        @click="useAppWidePrompt"
                    >
                        <Loader2 v-if="clearForm.processing" class="animate-spin" />
                        <RotateCcw v-else />
                        Use the app-wide prompt
                    </Button>
                    <div class="flex flex-wrap gap-2 sm:ml-auto">
                        <Button type="button" variant="outline" @click="open = false">Cancel</Button>
                        <Button type="submit" :disabled="!canSave">
                            <Loader2 v-if="form.processing" class="animate-spin" />
                            Save
                        </Button>
                    </div>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
