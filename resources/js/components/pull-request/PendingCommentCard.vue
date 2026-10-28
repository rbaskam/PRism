<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import type { PendingReviewComment } from '@/types';
import { router } from '@inertiajs/vue3';
import { Clock, Loader2, Pencil, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import { formatLineRange } from './diff';
import MarkdownText from './MarkdownText.vue';
import { pageVisit } from './utils';

/**
 * A comment waiting to be posted with the user's review, shown under the lines it's on. It can be edited or removed
 * until the review is submitted.
 */
const props = withDefaults(
    defineProps<{
        comment: PendingReviewComment;
        /** Show the file and lines, for comments listed away from their lines. */
        showLocation?: boolean;
    }>(),
    {
        showLocation: false,
    },
);

const editing = ref(false);
const draft = ref(props.comment.body);
const processing = ref(false);
const error = ref<string | undefined>();

function startEditing(): void {
    draft.value = props.comment.body;
    error.value = undefined;
    editing.value = true;
}

function save(): void {
    if (draft.value.trim() === '' || processing.value) {
        return;
    }

    router.patch(
        route('pending-comments.update', { pendingComment: props.comment.id }),
        { body: draft.value.trim() },
        pageVisit({
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
            onError: (errors) => (error.value = errors.body),
            onSuccess: () => (editing.value = false),
        }),
    );
}

function remove(): void {
    router.delete(route('pending-comments.destroy', { pendingComment: props.comment.id }), pageVisit({ onStart: () => (processing.value = true) }));
}

function handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        save();
    } else if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        editing.value = false;
    }
}
</script>

<template>
    <div class="overflow-hidden rounded-lg border border-dashed border-primary/50 bg-card text-card-foreground" :data-pending-comment-id="comment.id">
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b px-3 py-2">
            <Badge variant="outline" class="border-primary/50 px-1.5 text-[10px] text-foreground">
                <Clock />
                Pending
            </Badge>
            <span class="min-w-0 truncate text-xs text-muted-foreground">
                <template v-if="showLocation">
                    <span class="font-mono">{{ comment.path }}</span> ·
                </template>
                {{ formatLineRange(comment.start_line ?? comment.line, comment.line) }}{{ comment.side === 'LEFT' ? ' (removed code)' : '' }}
                · posted when you submit your review
            </span>
            <div v-if="!editing" class="ml-auto flex items-center gap-1">
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-7 [&_svg]:size-3.5"
                    title="Edit"
                    aria-label="Edit pending comment"
                    @click="startEditing"
                >
                    <Pencil />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-7 hover:text-red-600 dark:hover:text-red-400 [&_svg]:size-3.5"
                    title="Remove from review"
                    aria-label="Remove pending comment"
                    :disabled="processing"
                    @click="remove"
                >
                    <Loader2 v-if="processing" class="animate-spin" />
                    <Trash2 v-else />
                </Button>
            </div>
        </div>

        <form v-if="editing" class="space-y-2 px-3 py-3" @submit.prevent="save">
            <Textarea v-model="draft" class="min-h-20 text-sm" aria-label="Edit pending comment" @keydown="handleKeydown" />
            <InputError :message="error" />
            <div class="flex justify-end gap-2">
                <Button type="button" variant="ghost" size="sm" class="h-8" @click="editing = false">Cancel</Button>
                <Button type="submit" size="sm" class="h-8" :disabled="processing || draft.trim() === ''">
                    <Loader2 v-if="processing" class="animate-spin" />
                    Save
                </Button>
            </div>
        </form>
        <MarkdownText v-else :text="comment.body" class="px-3 py-3" />
    </div>
</template>
