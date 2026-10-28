<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { PendingReviewComment, PullRequest, PullRequestPermissions, ReviewFinding, SubmitReviewEvent } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { Check, Clock, Loader2, MessageSquare, XCircle } from 'lucide-vue-next';
import { computed, watch, type Component } from 'vue';
import { formatLineRange } from './diff';
import SettingsHint from './SettingsHint.vue';
import SeverityBadge from './SeverityBadge.vue';
import { failedWithFlash, findingLocation, pageVisit, refreshConversation } from './utils';

/**
 * Approve, request changes or comment on the pull request, optionally attaching AI findings as inline comments.
 */
const props = defineProps<{
    pullRequest: PullRequest;
    findings: ReviewFinding[];
    /** Comments on lines added to the review, posted with it. */
    pendingComments: PendingReviewComment[];
    permissions: PullRequestPermissions;
    initialEvent: SubmitReviewEvent;
}>();

const open = defineModel<boolean>('open', { required: true });

interface EventOption {
    value: SubmitReviewEvent;
    label: string;
    description: string;
    icon: Component;
    selectedClass: string;
    iconClass: string;
    submitClass: string;
}

const eventOptions: EventOption[] = [
    {
        value: 'APPROVE',
        label: 'Approve',
        description: 'Good to merge.',
        icon: Check,
        selectedClass: 'border-emerald-500 bg-emerald-50 ring-1 ring-emerald-500 dark:bg-emerald-500/10',
        iconClass: 'text-emerald-600 dark:text-emerald-400',
        submitClass: 'bg-emerald-600 text-white hover:bg-emerald-700 dark:bg-emerald-600 dark:text-white dark:hover:bg-emerald-500',
    },
    {
        value: 'REQUEST_CHANGES',
        label: 'Request changes',
        description: 'Must be fixed before merging.',
        icon: XCircle,
        selectedClass: 'border-red-500 bg-red-50 ring-1 ring-red-500 dark:bg-red-500/10',
        iconClass: 'text-red-600 dark:text-red-400',
        submitClass: 'bg-red-600 text-white hover:bg-red-700 dark:bg-red-600 dark:text-white dark:hover:bg-red-500',
    },
    {
        value: 'COMMENT',
        label: 'Comment',
        description: 'Feedback without a verdict.',
        icon: MessageSquare,
        selectedClass: 'border-primary bg-primary/5 ring-1 ring-primary',
        iconClass: 'text-foreground',
        submitClass: '',
    },
];

const form = useForm<{ event: SubmitReviewEvent; body: string; finding_ids: number[] }>({
    event: 'COMMENT',
    body: '',
    finding_ids: [],
});

/** Findings that can still be attached: not posted before and not fixed since. */
const attachableFindings = computed(() => props.findings.filter((finding) => !finding.is_posted && !finding.is_resolved));

const selectedOption = computed(() => eventOptions.find((option) => option.value === form.event) ?? eventOptions[2]);

const isBodyRequired = computed(
    () => form.event === 'REQUEST_CHANGES' || (form.event === 'COMMENT' && form.finding_ids.length === 0 && props.pendingComments.length === 0),
);

const canSubmit = computed(() => !form.processing && (!isBodyRequired.value || form.body.trim() !== ''));

const bodyPlaceholder = computed(() => {
    switch (form.event) {
        case 'APPROVE':
            return 'Optional message';
        case 'REQUEST_CHANGES':
            return 'What needs to change before this can merge?';
        default:
            return form.finding_ids.length > 0 || props.pendingComments.length > 0 ? 'Optional summary for your comments' : 'Leave a comment';
    }
});

function isEventDisabled(event: SubmitReviewEvent): boolean {
    return event !== 'COMMENT' && !props.permissions.can_approve;
}

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    form.event = isEventDisabled(props.initialEvent) ? 'COMMENT' : props.initialEvent;
    form.finding_ids = form.finding_ids.filter((id) => attachableFindings.value.some((finding) => finding.id === id));
    form.clearErrors();
});

function toggleFinding(id: number, checked: boolean): void {
    form.finding_ids = checked ? [...form.finding_ids, id] : form.finding_ids.filter((selectedId) => selectedId !== id);
}

function selectAllFindings(select: boolean): void {
    form.finding_ids = select ? attachableFindings.value.map((finding) => finding.id) : [];
}

function submit(): void {
    if (!canSubmit.value) {
        return;
    }

    form.transform((data) => ({
        event: data.event,
        body: data.body.trim() === '' ? null : data.body,
        ...(props.permissions.can_post_findings && data.finding_ids.length > 0 ? { finding_ids: data.finding_ids } : {}),
    })).post(
        route('pull-requests.reviews.store', { pullRequest: props.pullRequest.id }),
        pageVisit({
            onSuccess: (page) => {
                if (failedWithFlash(page)) {
                    return;
                }

                form.reset();
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
        <DialogContent class="max-h-[90vh] max-w-2xl overflow-y-auto">
            <DialogHeader>
                <DialogTitle>Submit your review</DialogTitle>
                <DialogDescription>
                    Posted to GitHub as your review of {{ pullRequest.repository?.full_name }}#{{ pullRequest.number }}.
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <div role="radiogroup" aria-label="Review type" class="grid gap-2 sm:grid-cols-3">
                        <button
                            v-for="option in eventOptions"
                            :key="option.value"
                            type="button"
                            role="radio"
                            :aria-checked="form.event === option.value"
                            :disabled="isEventDisabled(option.value)"
                            :class="
                                cn(
                                    'flex flex-col items-start gap-1 rounded-lg border p-3 text-left transition-colors hover:bg-muted/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-transparent',
                                    form.event === option.value && option.selectedClass,
                                )
                            "
                            @click="form.event = option.value"
                        >
                            <span class="flex items-center gap-1.5 text-sm font-medium">
                                <component :is="option.icon" :class="cn('size-4', option.iconClass)" />
                                {{ option.label }}
                            </span>
                            <span class="text-xs text-muted-foreground">{{ option.description }}</span>
                        </button>
                    </div>
                    <p v-if="!permissions.can_approve" class="text-xs text-muted-foreground">
                        This is your own pull request, so GitHub only lets you comment on it.
                    </p>
                    <InputError :message="form.errors.event" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="review-body">
                        Message
                        <span v-if="isBodyRequired" class="font-normal text-muted-foreground">(required)</span>
                        <span v-else class="font-normal text-muted-foreground">(optional)</span>
                    </Label>
                    <Textarea id="review-body" v-model="form.body" :placeholder="bodyPlaceholder" class="min-h-28" @keydown="handleKeydown" />
                    <InputError :message="form.errors.body" />
                </div>

                <div v-if="pendingComments.length > 0" class="grid gap-2">
                    <p class="text-sm font-medium">
                        Your comments
                        <span class="font-normal text-muted-foreground">({{ pendingComments.length }}, posted with this review)</span>
                    </p>
                    <ul class="max-h-40 divide-y overflow-y-auto rounded-md border">
                        <li v-for="comment in pendingComments" :key="comment.id" class="flex items-start gap-2 px-3 py-2">
                            <Clock class="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
                            <span class="min-w-0 flex-1 space-y-0.5">
                                <span class="block truncate font-mono text-xs text-muted-foreground"
                                    >{{ comment.path }} · {{ formatLineRange(comment.start_line ?? comment.line, comment.line) }}</span
                                >
                                <span class="line-clamp-2 block text-sm">{{ comment.body }}</span>
                            </span>
                        </li>
                    </ul>
                </div>

                <div v-if="attachableFindings.length > 0" class="grid gap-2">
                    <template v-if="permissions.can_post_findings">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-medium">
                                Attach AI findings
                                <span class="font-normal text-muted-foreground"
                                    >({{ form.finding_ids.length }} of {{ attachableFindings.length }})</span
                                >
                            </p>
                            <div class="flex items-center gap-2 text-xs">
                                <button
                                    type="button"
                                    class="text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                                    @click="selectAllFindings(true)"
                                >
                                    Select all
                                </button>
                                <button
                                    type="button"
                                    class="text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                                    @click="selectAllFindings(false)"
                                >
                                    None
                                </button>
                            </div>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Findings with a file and line become inline comments on the diff; the rest are added to the review message.
                        </p>
                        <ul class="max-h-56 divide-y overflow-y-auto rounded-md border">
                            <li v-for="finding in attachableFindings" :key="finding.id">
                                <label class="flex cursor-pointer items-start gap-2.5 px-3 py-2 hover:bg-muted/50">
                                    <Checkbox
                                        class="mt-0.5 size-4"
                                        :checked="form.finding_ids.includes(finding.id)"
                                        @update:checked="(checked: boolean) => toggleFinding(finding.id, checked)"
                                    />
                                    <span class="min-w-0 flex-1 space-y-0.5">
                                        <span class="flex items-start gap-2">
                                            <SeverityBadge :severity="finding.severity" />
                                            <span class="text-sm leading-5">{{ finding.title }}</span>
                                        </span>
                                        <span v-if="findingLocation(finding)" class="block truncate font-mono text-xs text-muted-foreground">
                                            {{ findingLocation(finding) }}
                                        </span>
                                    </span>
                                </label>
                            </li>
                        </ul>
                        <InputError :message="form.errors.finding_ids" />
                    </template>
                    <SettingsHint v-else message="Attaching AI findings is off" />
                </div>

                <DialogFooter class="gap-2">
                    <Button type="button" variant="outline" @click="open = false">Cancel</Button>
                    <Button type="submit" :disabled="!canSubmit" :class="selectedOption.submitClass">
                        <Loader2 v-if="form.processing" class="animate-spin" />
                        <component :is="selectedOption.icon" v-else />
                        {{ selectedOption.value === 'COMMENT' ? 'Submit comment' : selectedOption.label }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
