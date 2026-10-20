<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { severityStyles } from '@/lib/pull-requests';
import type { ReviewFinding } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { Loader2, MapPin, Send } from 'lucide-vue-next';
import { computed, watch } from 'vue';
import { failedWithFlash, findingLocation, pageVisit, refreshConversation } from './utils';

/**
 * Lets the user edit an AI finding before it is written to the pull request on GitHub.
 */
const props = defineProps<{
    finding: ReviewFinding | null;
}>();

const open = defineModel<boolean>('open', { required: true });

const form = useForm({ body: '' });

const location = computed(() => (props.finding ? findingLocation(props.finding) : null));
const canPlaceInline = computed(() => props.finding?.path != null && props.finding?.line != null);

watch(
    () => [open.value, props.finding?.id] as const,
    ([isOpen]) => {
        if (isOpen && props.finding) {
            form.body = props.finding.comment_body;
            form.clearErrors();
        }
    },
    { immediate: true },
);

function submit(): void {
    if (!props.finding || !form.body.trim()) {
        return;
    }

    form.post(
        route('findings.post', { finding: props.finding.id }),
        pageVisit({
            onSuccess: (page) => {
                if (failedWithFlash(page)) {
                    return;
                }

                open.value = false;
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
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-2xl">
            <DialogHeader>
                <DialogTitle>Post finding to the pull request</DialogTitle>
                <DialogDescription>Edit the comment before it goes to GitHub. It's posted under your account.</DialogDescription>
            </DialogHeader>

            <form v-if="finding" class="grid gap-3" @submit.prevent="submit">
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <Badge :variant="severityStyles[finding.severity].badge">{{ severityStyles[finding.severity].label }}</Badge>
                    <span class="font-medium">{{ finding.title }}</span>
                </div>

                <p class="flex items-start gap-2 rounded-md bg-muted/60 px-3 py-2 text-xs text-muted-foreground">
                    <MapPin class="mt-px size-3.5 shrink-0" />
                    <span v-if="canPlaceInline">
                        Placed on <code class="rounded bg-background px-1 py-0.5 font-mono text-foreground">{{ location }}</code> in the diff when
                        that line is part of the change. Otherwise it's added as a pull request comment that mentions the file and line.
                    </span>
                    <span v-else-if="location">
                        Added as a pull request comment that mentions
                        <code class="rounded bg-background px-1 py-0.5 font-mono text-foreground">{{ location }}</code
                        >.
                    </span>
                    <span v-else>Added as a pull request comment, since this finding isn't tied to a line.</span>
                </p>

                <div class="grid gap-1.5">
                    <Textarea
                        v-model="form.body"
                        class="min-h-56 font-mono text-xs leading-relaxed"
                        aria-label="Comment"
                        autofocus
                        @keydown="handleKeydown"
                    />
                    <InputError :message="form.errors.body" />
                    <p class="text-xs text-muted-foreground">Markdown is supported. Press ⌘/Ctrl + Enter to post.</p>
                </div>

                <DialogFooter class="gap-2">
                    <Button type="button" variant="outline" @click="open = false">Cancel</Button>
                    <Button type="submit" :disabled="form.processing || !form.body.trim()">
                        <Loader2 v-if="form.processing" class="animate-spin" />
                        <Send v-else />
                        Post comment
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
