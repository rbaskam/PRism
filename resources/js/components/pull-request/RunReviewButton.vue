<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { Link } from '@inertiajs/vue3';
import { Bot, Info, Loader2, Sparkles } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { failedWithFlash, startReviewRun } from './utils';

/**
 * "Review with AI": shows the prompt Claude will run with before queueing the review.
 */
const props = withDefaults(
    defineProps<{
        pullRequestId: number;
        reviewPrompt: string;
        canRunReview: boolean;
        hasRuns: boolean;
        isRunning: boolean;
        size?: 'default' | 'sm';
    }>(),
    {
        size: 'sm',
    },
);

const open = ref(false);
const processing = ref(false);

const label = computed(() => (props.hasRuns ? 'Re-run AI review' : 'Review with AI'));

function confirm(): void {
    startReviewRun(props.pullRequestId, {
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
        onSuccess: (page) => {
            if (!failedWithFlash(page)) {
                open.value = false;
            }
        },
    });
}
</script>

<template>
    <div class="contents">
        <Tooltip v-if="!canRunReview">
            <TooltipTrigger as-child>
                <span tabindex="0" class="inline-flex">
                    <Button :size="size" disabled>
                        <Sparkles />
                        {{ label }}
                    </Button>
                </span>
            </TooltipTrigger>
            <TooltipContent class="max-w-xs text-xs">
                Claude isn't connected yet.
                <Link :href="route('settings.claude')" class="font-medium underline underline-offset-2">Connect Claude in Settings</Link>
                to run AI reviews.
            </TooltipContent>
        </Tooltip>

        <Button v-else-if="isRunning" :size="size" disabled>
            <Loader2 class="animate-spin" />
            Reviewing…
        </Button>

        <Button v-else :size="size" @click="open = true">
            <Sparkles />
            {{ label }}
        </Button>

        <Dialog v-model:open="open">
            <DialogContent class="max-w-2xl">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <Bot class="size-5 text-violet-500" />
                        {{ hasRuns ? 'Re-run the AI review?' : 'Review this pull request with AI?' }}
                    </DialogTitle>
                    <DialogDescription>
                        Claude Code reviews the pull request read-only in the background. Nothing is written to GitHub until you decide to post it.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Prompt sent to Claude</p>
                        <Link
                            :href="route('settings.review')"
                            class="text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground"
                        >
                            Edit in Settings → PR review
                        </Link>
                    </div>
                    <pre class="max-h-72 overflow-auto whitespace-pre-wrap rounded-md border bg-muted/50 p-3 font-mono text-xs leading-relaxed">{{
                        reviewPrompt
                    }}</pre>
                </div>

                <p v-if="hasRuns" class="flex items-start gap-2 rounded-md bg-sky-50 p-3 text-xs text-sky-900 dark:bg-sky-500/10 dark:text-sky-200">
                    <Info class="mt-px size-4 shrink-0" />
                    Previous findings and your decisions are sent to Claude so it re-checks them instead of repeating them.
                </p>

                <DialogFooter class="gap-2">
                    <Button variant="outline" @click="open = false">Cancel</Button>
                    <Button :disabled="processing" @click="confirm">
                        <Loader2 v-if="processing" class="animate-spin" />
                        <Sparkles v-else />
                        Start review
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
