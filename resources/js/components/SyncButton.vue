<script setup lang="ts">
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import type { SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useEventListener } from '@vueuse/core';
import { ArrowUpCircle, Bot, Lock, RefreshCw } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const page = usePage<SharedData>();
const reviewing = computed(() => page.props.counts.reviewing ?? []);
const syncing = ref(false);
const locking = ref(false);
const lockShortcut = navigator.platform.toLowerCase().includes('mac') ? '⌘L' : 'Ctrl+L';

function sync(): void {
    router.post(
        route('sync'),
        {},
        {
            preserveScroll: true,
            onStart: () => (syncing.value = true),
            onFinish: () => {
                // Repositories sync in the background; reload the page shortly after to pick up the results.
                setTimeout(() => {
                    syncing.value = false;
                    router.reload();
                }, 4000);
            },
        },
    );
}

function lock(): void {
    router.post(
        route('lock.store'),
        {},
        {
            onStart: () => (locking.value = true),
            onFinish: () => (locking.value = false),
        },
    );
}

/** ⌘/Ctrl+L locks the app, unless the user is typing somewhere. */
useEventListener(window, 'keydown', (event: KeyboardEvent) => {
    if (
        !page.props.appLock?.enabled ||
        locking.value ||
        event.key?.toLowerCase() !== 'l' ||
        !(event.metaKey || event.ctrlKey) ||
        event.altKey ||
        event.shiftKey
    ) {
        return;
    }

    if ((event.target as HTMLElement | null)?.closest('input, textarea, select, [contenteditable]:not([contenteditable="false"])')) {
        return;
    }

    event.preventDefault();
    lock();
});
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem v-if="page.props.update">
            <SidebarMenuButton
                as-child
                :tooltip="`Update available: v${page.props.update.version}`"
                class="bg-emerald-50 font-medium text-emerald-800 hover:bg-emerald-100 hover:text-emerald-900 dark:bg-emerald-500/15 dark:text-emerald-300 dark:hover:bg-emerald-500/25 dark:hover:text-emerald-200"
            >
                <Link :href="route('settings.about')">
                    <ArrowUpCircle class="text-emerald-600 dark:text-emerald-400" />
                    <span>Update available: v{{ page.props.update.version }}</span>
                </Link>
            </SidebarMenuButton>
        </SidebarMenuItem>
        <SidebarMenuItem v-if="reviewing.length === 1">
            <SidebarMenuButton as-child class="text-muted-foreground" :tooltip="`Claude is reviewing ${reviewing[0].reference}`">
                <Link :href="route('pull-requests.show', { pullRequest: reviewing[0].pull_request_id })">
                    <Bot class="animate-pulse text-violet-500" />
                    <span>1 AI review running</span>
                </Link>
            </SidebarMenuButton>
        </SidebarMenuItem>
        <SidebarMenuItem v-else-if="reviewing.length > 1">
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton class="text-muted-foreground" :tooltip="`${reviewing.length} AI reviews running`">
                        <Bot class="animate-pulse text-violet-500" />
                        <span>{{ reviewing.length }} AI reviews running</span>
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent side="right" align="end" class="w-72">
                    <DropdownMenuLabel class="font-normal text-muted-foreground">Claude is reviewing</DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem v-for="review in reviewing" :key="review.pull_request_id" as-child>
                        <Link
                            :href="route('pull-requests.show', { pullRequest: review.pull_request_id })"
                            class="flex w-full flex-col items-start gap-0.5"
                        >
                            <span class="w-full truncate text-sm">{{ review.title }}</span>
                            <span class="text-xs text-muted-foreground">
                                {{ review.reference }} · {{ review.status === 'queued' ? 'waiting to start' : 'reviewing' }}
                            </span>
                        </Link>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
        <SidebarMenuItem>
            <SidebarMenuButton :disabled="syncing" tooltip="Sync with GitHub" @click="sync">
                <RefreshCw :class="{ 'animate-spin': syncing }" />
                <span>{{ syncing ? 'Syncing…' : 'Sync with GitHub' }}</span>
            </SidebarMenuButton>
        </SidebarMenuItem>
        <SidebarMenuItem v-if="page.props.appLock?.enabled">
            <SidebarMenuButton :disabled="locking" :tooltip="`Lock (${lockShortcut})`" @click="lock">
                <Lock />
                <span>Lock</span>
                <kbd class="ml-auto font-mono text-[10px] text-muted-foreground">{{ lockShortcut }}</kbd>
            </SidebarMenuButton>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
