<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import SyncButton from '@/components/SyncButton.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { quickSwitcherShortcut, useQuickSwitcher } from '@/composables/useQuickSwitcher';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { FolderGit2, Inbox, LayoutGrid, Search, Settings } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();
const quickSwitcher = useQuickSwitcher();

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'My pull requests',
        href: route('dashboard'),
        icon: LayoutGrid,
        badge: page.props.counts.awaiting_review,
        badgeLabel: 'awaiting your review',
    },
    { title: 'Repositories', href: route('repositories.index'), icon: FolderGit2 },
    { title: 'Inbox', href: route('inbox.index'), icon: Inbox, badge: page.props.counts.unread_inbox, badgeLabel: 'unread' },
    { title: 'Settings', href: route('settings'), icon: Settings },
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('dashboard')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        variant="outline"
                        class="text-muted-foreground"
                        :tooltip="`Search (${quickSwitcherShortcut})`"
                        aria-haspopup="dialog"
                        aria-keyshortcuts="Meta+K Control+K"
                        @click="quickSwitcher.open()"
                    >
                        <Search />
                        <span>Search…</span>
                        <kbd class="ml-auto font-mono text-[10px]" aria-hidden="true">{{ quickSwitcherShortcut }}</kbd>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <SyncButton />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
