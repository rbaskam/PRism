<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { useExternalLink } from '@/composables/useExternalLink';
import { getJson, postJson } from '@/lib/http';
import { timeAgo } from '@/lib/pull-requests';
import { cn } from '@/lib/utils';
import type { AppAlert, SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { AtSign, Bell, CheckCheck, CircleCheck, GitPullRequest, GitPullRequestArrow, MessageSquare, Settings, Sparkles } from 'lucide-vue-next';
import { ref, watch, type Component } from 'vue';

/**
 * The bell in the header: the alerts PRism raised, newest first. Opening one marks it read and goes to where it
 * happened, e.g. a pull request's AI findings or its conversation. The list is loaded when the bell is opened, and
 * marking alerts as read never reloads the page.
 */
const page = usePage<SharedData>();
const { openExternal } = useExternalLink();

const open = ref(false);
const loading = ref(false);
const error = ref<string | null>(null);
const alerts = ref<AppAlert[]>([]);
const unread = ref(page.props.counts.unread_alerts);

watch(
    () => page.props.counts.unread_alerts,
    (count) => (unread.value = count),
);

const icons: Record<string, Component> = {
    review_requests: GitPullRequestArrow,
    mentions: AtSign,
    my_pull_requests: GitPullRequest,
    commented: MessageSquare,
    ci: CircleCheck,
    new_pull_requests: GitPullRequest,
    review_finished: Sparkles,
};

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const result = await getJson<{ alerts: AppAlert[]; unread: number }>(route('alerts.index'), "Couldn't load your notifications");
        alerts.value = result.alerts;
        unread.value = result.unread;
    } catch (failure) {
        error.value = failure instanceof Error ? failure.message : "Couldn't load your notifications.";
    } finally {
        loading.value = false;
    }
}

watch(open, (isOpen) => {
    if (isOpen) {
        load();
    }
});

async function markAllAsRead(): Promise<void> {
    alerts.value = alerts.value.map((alert) => ({ ...alert, is_read: true }));
    unread.value = 0;

    try {
        await postJson(route('alerts.read-all'), {}, "Couldn't mark your notifications as read");
    } catch (failure) {
        error.value = failure instanceof Error ? failure.message : "Couldn't mark your notifications as read.";
    }
}

async function openAlert(alert: AppAlert): Promise<void> {
    if (!alert.is_read) {
        alert.is_read = true;
        unread.value = Math.max(0, unread.value - 1);
        await postJson(route('alerts.read', { alert: alert.id })).catch(() => undefined);
    }

    if (!alert.url) {
        return;
    }

    if (alert.url.startsWith('/')) {
        open.value = false;
        router.visit(alert.url);

        return;
    }

    openExternal(alert.url);
}
</script>

<template>
    <Sheet v-model:open="open">
        <Button
            variant="ghost"
            size="icon"
            class="relative size-9"
            :aria-label="unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'"
            :title="unread > 0 ? `${unread} unread notification${unread === 1 ? '' : 's'}` : 'Notifications'"
            @click="open = true"
        >
            <Bell class="!size-5" />
            <span
                v-if="unread > 0"
                class="absolute right-0.5 top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-semibold leading-none text-white"
            >
                {{ unread > 99 ? '99+' : unread }}
            </span>
        </Button>

        <SheetContent side="right" class="flex w-full flex-col gap-0 p-0 sm:max-w-md">
            <SheetHeader class="flex-row items-center justify-between gap-2 space-y-0 border-b py-3 pl-4 pr-12 text-left">
                <div class="space-y-0.5">
                    <SheetTitle>Notifications</SheetTitle>
                    <SheetDescription class="text-xs">Everything {{ page.props.name }} alerted you about in the last 30 days.</SheetDescription>
                </div>
                <Button variant="ghost" size="sm" class="shrink-0" :disabled="unread === 0" @click="markAllAsRead">
                    <CheckCheck />
                    Mark all as read
                </Button>
            </SheetHeader>

            <div class="min-h-0 flex-1 overflow-y-auto">
                <p
                    v-if="error"
                    class="m-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"
                    role="alert"
                >
                    {{ error }}
                </p>

                <div v-if="loading && alerts.length === 0" class="space-y-4 p-4" aria-busy="true" aria-label="Loading your notifications">
                    <div v-for="row in 4" :key="row" class="flex gap-3">
                        <Skeleton class="size-4 rounded-full" />
                        <div class="flex-1 space-y-2">
                            <Skeleton class="h-3 w-2/3" />
                            <Skeleton class="h-3 w-1/2" />
                        </div>
                    </div>
                </div>

                <div v-else-if="alerts.length === 0 && !error" class="flex flex-col items-center gap-3 px-6 py-16 text-center">
                    <Bell class="size-8 text-muted-foreground" />
                    <div class="space-y-1">
                        <p class="font-medium">No notifications yet</p>
                        <p class="text-sm text-muted-foreground">Review requests, new pull requests and finished AI reviews show up here.</p>
                    </div>
                </div>

                <ul v-else class="divide-y">
                    <li v-for="alert in alerts" :key="alert.id">
                        <button
                            type="button"
                            :class="
                                cn(
                                    'flex w-full items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:outline-none',
                                    !alert.is_read && 'bg-primary/[0.04]',
                                )
                            "
                            @click="openAlert(alert)"
                        >
                            <span
                                :class="cn('mt-1.5 size-2 shrink-0 rounded-full', alert.is_read ? 'bg-transparent' : 'bg-primary')"
                                :aria-label="alert.is_read ? undefined : 'Unread'"
                            />
                            <component :is="icons[alert.type] ?? Bell" class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                            <span class="min-w-0 flex-1 space-y-0.5">
                                <span :class="cn('block text-sm', alert.is_read ? 'text-muted-foreground' : 'font-semibold')">{{ alert.title }}</span>
                                <span v-if="alert.body" class="block truncate text-xs text-muted-foreground">{{ alert.body }}</span>
                            </span>
                            <time v-if="alert.created_at" :datetime="alert.created_at" class="shrink-0 pt-0.5 text-xs text-muted-foreground">
                                {{ timeAgo(alert.created_at) }}
                            </time>
                        </button>
                    </li>
                </ul>
            </div>

            <div class="border-t px-4 py-2.5">
                <Link
                    :href="route('settings.notifications')"
                    class="inline-flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground"
                    @click="open = false"
                >
                    <Settings class="size-3.5" />
                    Choose what you get alerts about
                </Link>
            </div>
        </SheetContent>
    </Sheet>
</template>
