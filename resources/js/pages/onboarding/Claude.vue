<script setup lang="ts">
import ClaudeConnectionForm, { type ClaudeConnectionSettings } from '@/components/ClaudeConnectionForm.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import OnboardingLayout from '@/layouts/OnboardingLayout.vue';
import type { ClaudeStatus } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { AlertTriangle, ArrowLeft, Check, Loader2, ShieldCheck } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    status: ClaudeStatus;
    settings: ClaudeConnectionSettings;
}>();

const hasUnsavedChanges = ref(false);

const unsavedMessage = computed(() =>
    props.settings.auth_mode === null
        ? 'Nothing is saved until you click "Connect Claude". You can also skip this and connect Claude later.'
        : 'You have unsaved changes. Click "Connect Claude" to save them before you finish.',
);
const completing = ref(false);

function complete(): void {
    router.post(
        route('onboarding.complete'),
        {},
        {
            onStart: () => (completing.value = true),
            onFinish: () => (completing.value = false),
        },
    );
}
</script>

<template>
    <OnboardingLayout
        :step="3"
        title="Connect Claude"
        description="Optional: connect Claude Code to run AI reviews of pull requests. You can do this later in Settings."
    >
        <Head title="Connect Claude" />

        <div class="space-y-6">
            <div class="flex items-start gap-3 rounded-lg border bg-muted/40 p-4 text-sm">
                <ShieldCheck class="mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-500" />
                <div class="space-y-1">
                    <p class="font-medium">Reviews run on your computer, read-only</p>
                    <p class="text-muted-foreground">
                        {{ $page.props.name }} runs Claude Code locally with read-only tools: it can read the code and fetch the pull request, but it
                        never writes to the pull request by itself. You decide what, if anything, gets posted to GitHub.
                    </p>
                </div>
            </div>

            <ClaudeConnectionForm v-model:dirty="hasUnsavedChanges" :status="status" :settings="settings" submit-label="Connect Claude" />

            <Separator />

            <p v-if="hasUnsavedChanges" class="flex items-start gap-2 text-sm text-amber-700 dark:text-amber-400">
                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                {{ unsavedMessage }}
            </p>

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between">
                <Button variant="ghost" as-child>
                    <Link :href="route('onboarding.repositories')">
                        <ArrowLeft />
                        Back
                    </Link>
                </Button>
                <Button v-if="settings.auth_mode" :disabled="completing" @click="complete">
                    <Loader2 v-if="completing" class="animate-spin" />
                    <Check v-else />
                    Finish
                </Button>
                <Button v-else variant="outline" :disabled="completing" @click="complete">
                    <Loader2 v-if="completing" class="animate-spin" />
                    Skip for now — connect Claude later in Settings
                </Button>
            </div>
        </div>
    </OnboardingLayout>
</template>
