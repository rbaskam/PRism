<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Info, Loader2, RotateCcw } from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps<{
    settings: {
        prompt: string;
        allow_posting_findings: boolean;
        allow_replying_to_comments: boolean;
        auto_review_on_request: boolean;
        allow_code_changes: boolean;
        allow_pushing: boolean;
    };
    defaultPrompt: string;
    /** Placeholder (e.g. "{number}") => what it is replaced with. */
    placeholders: Record<string, string>;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'PR review settings', href: route('settings.review') }];

const MAX_PROMPT_LENGTH = 20000;

const form = useForm({ ...props.settings });

const promptTextarea = ref<InstanceType<typeof Textarea> | null>(null);

const isDefaultPrompt = computed(() => form.prompt.trim() === props.defaultPrompt.trim());

type ToggleKey = 'allow_posting_findings' | 'allow_replying_to_comments' | 'auto_review_on_request' | 'allow_code_changes' | 'allow_pushing';

interface Toggle {
    key: ToggleKey;
    label: string;
    description: string;
    warning?: string;
    /** Only available while this other setting is on. */
    requires?: ToggleKey;
}

interface ToggleSection {
    title: string;
    description: string;
    toggles: Toggle[];
}

const permissionToggles: Toggle[] = [
    {
        key: 'allow_posting_findings',
        label: 'Allow posting findings to pull requests',
        description:
            'Adds a "Post to PR" button to each AI finding, and lets you attach findings as inline comments when you approve, request changes or comment. Nothing is posted until you click; reviews never post automatically. When off, findings stay in this app.',
    },
    {
        key: 'allow_replying_to_comments',
        label: 'Allow commenting and replying',
        description:
            'Lets you comment on lines in the diff, reply to and resolve review threads from the app, and ask Claude to draft replies that you edit before sending.',
    },
    {
        key: 'auto_review_on_request',
        label: 'Automatically run an AI review when my review is requested',
        description:
            'Starts a review as soon as a new review request for you (or one of your teams) arrives, so findings are ready when you open the PR.',
        warning: 'Every automatic review uses Claude credits, or your subscription usage limits.',
    },
];

const codeChangeToggles: Toggle[] = [
    {
        key: 'allow_code_changes',
        label: 'Let Claude change code in a worktree of your local checkout',
        description:
            'Fix with Claude works in a separate git worktree made from the local checkout of the repository (set one under Settings → GitHub). Your own checkout and branches are never touched, and nothing is pushed without your approval.',
        warning: 'Fixes use Claude credits, or your subscription usage limits.',
    },
    {
        key: 'allow_pushing',
        label: "Allow pushing Claude's approved changes and opening pull requests",
        description:
            'After you review and approve the changes, they can be pushed to GitHub and opened as a pull request. Nothing is pushed until you click to do it.',
        requires: 'allow_code_changes',
    },
];

const sections: ToggleSection[] = [
    {
        title: 'Permissions and automation',
        description: 'All off by default. Even when allowed, nothing is written to GitHub until you click a button.',
        toggles: permissionToggles,
    },
    {
        title: 'Code changes (Fix with Claude)',
        description: 'Let Claude fix findings for you. Off by default.',
        toggles: codeChangeToggles,
    },
];

function isUnavailable(toggle: Toggle): boolean {
    return toggle.requires !== undefined && !form[toggle.requires];
}

watch(
    () => form.allow_code_changes,
    (allowed) => {
        if (!allowed) {
            form.allow_pushing = false;
        }
    },
);

function insertPlaceholder(placeholder: string): void {
    const textarea = promptTextarea.value?.$el as HTMLTextAreaElement | undefined;
    const start = textarea?.selectionStart ?? form.prompt.length;
    const end = textarea?.selectionEnd ?? start;

    form.prompt = form.prompt.slice(0, start) + placeholder + form.prompt.slice(end);

    nextTick(() => {
        textarea?.focus();
        textarea?.setSelectionRange(start + placeholder.length, start + placeholder.length);
    });
}

function resetPrompt(): void {
    form.prompt = props.defaultPrompt;
}

function save(): void {
    form.put(route('settings.review.update'), {
        preserveScroll: true,
        onSuccess: () => form.defaults(),
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="PR review settings" />

        <SettingsLayout>
            <form class="space-y-12" @submit.prevent="save">
                <div class="space-y-6">
                    <div class="flex items-start justify-between gap-3">
                        <HeadingSmall title="Review prompt" description="What Claude is asked to do when it reviews a pull request." />
                        <Badge :variant="isDefaultPrompt ? 'muted' : 'info'" class="mt-0.5 shrink-0">{{
                            isDefaultPrompt ? 'Default' : 'Custom'
                        }}</Badge>
                    </div>

                    <div class="grid gap-3">
                        <Label for="review-prompt" class="sr-only">Review prompt</Label>
                        <Textarea
                            id="review-prompt"
                            ref="promptTextarea"
                            v-model="form.prompt"
                            rows="10"
                            spellcheck="false"
                            :maxlength="MAX_PROMPT_LENGTH"
                            class="min-h-56 resize-y font-mono text-[13px] leading-relaxed md:text-[13px]"
                        />
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-xs tabular-nums text-muted-foreground">
                                {{ form.prompt.length.toLocaleString() }} / {{ MAX_PROMPT_LENGTH.toLocaleString() }} characters
                            </span>
                            <Button type="button" variant="ghost" size="sm" :disabled="isDefaultPrompt" @click="resetPrompt">
                                <RotateCcw />
                                Reset to default
                            </Button>
                        </div>
                        <InputError :message="form.errors.prompt" />
                    </div>

                    <div class="space-y-2">
                        <p class="text-sm font-medium">
                            Placeholders <span class="font-normal text-muted-foreground">· click to insert at the cursor</span>
                        </p>
                        <div class="grid gap-1.5 sm:grid-cols-2">
                            <button
                                v-for="(description, placeholder) in placeholders"
                                :key="placeholder"
                                type="button"
                                :class="
                                    cn(
                                        'flex items-center gap-2 rounded-md border px-2.5 py-1.5 text-left text-xs transition-colors hover:bg-muted',
                                        form.prompt.includes(placeholder) && 'border-primary/40',
                                    )
                                "
                                @click="insertPlaceholder(placeholder)"
                            >
                                <code class="shrink-0 font-mono font-medium">{{ placeholder }}</code>
                                <span class="truncate text-muted-foreground">{{ description }}</span>
                            </button>
                        </div>
                    </div>

                    <div class="flex items-start gap-3 rounded-lg border bg-muted/40 p-4 text-sm">
                        <Info class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                        <div class="space-y-2">
                            <p class="font-medium">{{ $page.props.name }} adds these rules to every review automatically</p>
                            <ul class="list-disc space-y-1 pl-4 text-muted-foreground">
                                <li>
                                    During reviews Claude is read-only: it can read code and fetch the PR, but can't edit files or write to GitHub.
                                </li>
                                <li>
                                    Findings come back in a structured format (severity, category, file and line) so the app can list and track them.
                                </li>
                                <li>
                                    Previous findings and your decisions on them (not useful, not a blocker…) are included, so re-runs re-check them
                                    instead of reporting duplicates.
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div v-for="section in sections" :key="section.title" class="space-y-6">
                    <HeadingSmall :title="section.title" :description="section.description" />

                    <div class="divide-y rounded-lg border">
                        <div
                            v-for="toggle in section.toggles"
                            :key="toggle.key"
                            :class="cn('flex items-start justify-between gap-4 p-4 transition-opacity', isUnavailable(toggle) && 'opacity-50')"
                        >
                            <div class="space-y-1">
                                <Label :for="toggle.key" class="cursor-pointer">{{ toggle.label }}</Label>
                                <p class="text-sm text-muted-foreground">{{ toggle.description }}</p>
                                <p
                                    v-if="toggle.warning"
                                    :class="
                                        cn(
                                            'flex items-start gap-1.5 text-sm',
                                            form[toggle.key] ? 'text-amber-700 dark:text-amber-400' : 'text-muted-foreground',
                                        )
                                    "
                                >
                                    <AlertTriangle class="mt-0.5 size-3.5 shrink-0" />
                                    {{ toggle.warning }}
                                </p>
                                <InputError :message="form.errors[toggle.key]" />
                            </div>
                            <Switch :id="toggle.key" v-model:checked="form[toggle.key]" :disabled="isUnavailable(toggle)" class="mt-0.5" />
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <Button type="submit" :disabled="form.processing || !form.isDirty">
                        <Loader2 v-if="form.processing" class="animate-spin" />
                        Save
                    </Button>
                    <Transition
                        enter-active-class="transition ease-in-out"
                        enter-from-class="opacity-0"
                        leave-active-class="transition ease-in-out"
                        leave-to-class="opacity-0"
                    >
                        <p v-show="form.recentlySuccessful" class="text-sm text-muted-foreground">Saved.</p>
                    </Transition>
                    <p v-if="form.isDirty && !form.processing" class="text-sm text-muted-foreground">You have unsaved changes.</p>
                </div>
            </form>
        </SettingsLayout>
    </AppLayout>
</template>
