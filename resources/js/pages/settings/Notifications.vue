<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Switch } from '@/components/ui/switch';
import { useUnsavedChangesGuard } from '@/composables/useUnsavedChangesGuard';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { BellRing, Info, Loader2 } from 'lucide-vue-next';
import { ref } from 'vue';

type AlertKey =
    'review_requests' | 'mentions' | 'follow_up_activity' | 'my_pull_requests' | 'commented' | 'ci' | 'new_pull_requests' | 'review_finished';

const props = defineProps<{
    settings: { desktop: boolean; sound: string } & Record<AlertKey, boolean>;
    sounds: { value: string; label: string }[];
    desktopAvailable: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Notification settings', href: route('settings.notifications') }];

const form = useForm({ ...props.settings });

useUnsavedChangesGuard(() => form.isDirty);

interface Toggle {
    key: AlertKey;
    label: string;
    description: string;
}

const groups: { title: string; toggles: Toggle[] }[] = [
    {
        title: 'From GitHub',
        toggles: [
            {
                key: 'review_requests',
                label: 'Review requests',
                description: 'When you, or one of your teams, is newly asked to review a pull request, or a pull request is assigned to you.',
            },
            {
                key: 'mentions',
                label: 'Mentions',
                description: 'When you or one of your teams is @mentioned.',
            },
            {
                key: 'follow_up_activity',
                label: 'Later activity on those pull requests',
                description:
                    'Review requests and mentions alert once. Turn this on to be alerted again whenever one of those pull requests gets new comments or commits.',
            },
            {
                key: 'my_pull_requests',
                label: 'Activity on your pull requests',
                description: 'Each new comment, review or change on a pull request you opened.',
            },
            {
                key: 'commented',
                label: 'Replies where you commented',
                description: "New comments on pull requests you've commented on.",
            },
            {
                key: 'ci',
                label: 'CI results',
                description: 'When checks finish on your pull requests.',
            },
        ],
    },
    {
        title: 'In your repositories',
        toggles: [
            {
                key: 'new_pull_requests',
                label: 'New pull requests',
                description: 'When someone opens a pull request in a repository you track, even if no one asked you to review it.',
            },
        ],
    },
    {
        title: 'From Claude',
        toggles: [
            {
                key: 'review_finished',
                label: 'AI work finished',
                description: 'When Claude finishes a review (with the number of findings), answers a question about code, or has fixes ready.',
            },
        ],
    },
];

const sendingTest = ref(false);

function save(): void {
    form.put(route('settings.notifications.update'), {
        preserveScroll: true,
        onSuccess: () => form.defaults(),
    });
}

function sendTest(): void {
    router.post(
        route('settings.notifications.test'),
        { sound: form.sound },
        {
            preserveScroll: true,
            onStart: () => (sendingTest.value = true),
            onFinish: () => (sendingTest.value = false),
        },
    );
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Notification settings" />

        <SettingsLayout>
            <form class="space-y-6" @submit.prevent="save">
                <HeadingSmall
                    title="Notifications"
                    description="Choose what you get alerts about. They are listed under the bell at the top of every page, and shown on your desktop too when desktop notifications are on."
                />

                <div class="flex items-start gap-3 rounded-lg border bg-muted/40 p-4 text-sm text-muted-foreground">
                    <Info class="mt-0.5 size-4 shrink-0" />
                    <p>
                        {{ $page.props.name }} checks GitHub every minute and syncs your repositories every five minutes. Every GitHub notification
                        still shows up in the Inbox. Click an alert to open the pull request where it happened.
                    </p>
                </div>

                <div
                    v-if="!desktopAvailable"
                    class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
                >
                    <BellRing class="mt-0.5 size-4 shrink-0" />
                    <p>
                        Desktop notifications only appear when {{ $page.props.name }} runs as the desktop app. Your choices here are saved for when it
                        does.
                    </p>
                </div>

                <div class="divide-y rounded-lg border">
                    <div class="flex items-start justify-between gap-4 p-4">
                        <div class="space-y-1">
                            <Label for="desktop" class="cursor-pointer">Show desktop notifications</Label>
                            <p class="text-sm text-muted-foreground">
                                Turn this off to stop the pop-ups. Alerts you switch on below are still listed under the bell.
                            </p>
                            <InputError :message="form.errors.desktop" />
                        </div>
                        <Switch id="desktop" v-model:checked="form.desktop" class="mt-0.5" />
                    </div>

                    <div
                        :class="
                            cn(
                                'flex flex-col gap-3 p-4 transition-opacity sm:flex-row sm:items-start sm:justify-between',
                                !form.desktop && 'opacity-50',
                            )
                        "
                    >
                        <div class="space-y-1">
                            <Label for="sound">Sound</Label>
                            <p class="text-sm text-muted-foreground">
                                Played with every alert. Use <span class="font-medium">Send test notification</span> to hear it.
                            </p>
                            <InputError :message="form.errors.sound" />
                        </div>
                        <NativeSelect
                            id="sound"
                            :model-value="form.sound"
                            class="shrink-0 sm:w-44"
                            @update:model-value="(value) => (form.sound = String(value))"
                        >
                            <option v-for="sound in sounds" :key="sound.value" :value="sound.value">{{ sound.label }}</option>
                        </NativeSelect>
                    </div>

                    <template v-for="group in groups" :key="group.title">
                        <p class="bg-muted/40 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            {{ group.title }}
                        </p>
                        <div
                            v-for="toggle in group.toggles"
                            :key="toggle.key"
                            :class="cn('flex items-start justify-between gap-4 p-4 pl-8', toggle.key === 'follow_up_activity' && 'pl-12')"
                        >
                            <div class="space-y-1">
                                <Label :for="toggle.key" class="cursor-pointer">{{ toggle.label }}</Label>
                                <p class="text-sm text-muted-foreground">{{ toggle.description }}</p>
                                <InputError :message="form.errors[toggle.key]" />
                            </div>
                            <Switch :id="toggle.key" v-model:checked="form[toggle.key]" class="mt-0.5" />
                        </div>
                    </template>
                </div>

                <div class="flex flex-wrap items-center gap-3">
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
                    <Button type="button" variant="outline" class="ml-auto" :disabled="sendingTest || !desktopAvailable" @click="sendTest">
                        <Loader2 v-if="sendingTest" class="animate-spin" />
                        <BellRing v-else />
                        Send test notification
                    </Button>
                </div>
            </form>
        </SettingsLayout>
    </AppLayout>
</template>
