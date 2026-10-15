<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import RepositoryPicker, { MAX_TRACKED_REPOSITORIES, toRepositorySelection } from '@/components/RepositoryPicker.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import OnboardingLayout from '@/layouts/OnboardingLayout.vue';
import type { Repository } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, GitPullRequestArrow, Loader2 } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    tracked: Repository[];
    /** "owner/name" of untracked repositories with pull requests awaiting the user's review. */
    suggested: string[];
}>();

const form = useForm({
    repositories: props.tracked.map(toRepositorySelection),
    redirect_to: 'onboarding.claude',
});

const suggestedSummary = computed(() =>
    props.suggested.length === 1
        ? 'Your review is requested on pull requests in 1 repository, so it is selected for you.'
        : `Your review is requested on pull requests in ${props.suggested.length} repositories, so they are selected for you.`,
);

const canContinue = computed(() => form.repositories.length > 0 && form.repositories.length <= MAX_TRACKED_REPOSITORIES && !form.processing);

function save(): void {
    form.put(route('tracked-repositories.update'));
}
</script>

<template>
    <OnboardingLayout
        :step="2"
        title="Choose repositories"
        description="Pick the repositories whose pull requests you want to follow. You can change this any time in Settings."
    >
        <Head title="Choose repositories" />

        <form class="space-y-6" @submit.prevent="save">
            <div
                v-if="suggested.length > 0"
                class="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"
            >
                <GitPullRequestArrow class="mt-0.5 size-4 shrink-0" />
                <p>{{ suggestedSummary }}</p>
            </div>

            <RepositoryPicker v-model="form.repositories" :preselect="suggested" />

            <InputError :message="form.errors.repositories" />

            <Separator />

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between">
                <Button variant="ghost" as-child>
                    <Link :href="route('onboarding.github')">
                        <ArrowLeft />
                        Back
                    </Link>
                </Button>
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:items-center">
                    <Button variant="ghost" as-child>
                        <Link :href="route('onboarding.claude')">Skip for now</Link>
                    </Button>
                    <Button type="submit" :disabled="!canContinue">
                        <Loader2 v-if="form.processing" class="animate-spin" />
                        <template v-if="form.repositories.length === 0">Select at least one repository</template>
                        <template v-else>
                            Track {{ form.repositories.length }} {{ form.repositories.length === 1 ? 'repository' : 'repositories' }}
                            <ArrowRight v-if="!form.processing" />
                        </template>
                    </Button>
                </div>
            </div>
        </form>
    </OnboardingLayout>
</template>
