<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import SyncButton from '@/components/SyncButton.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { FolderGit2, Inbox, LayoutGrid, Settings } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();

const mainNavItems = computed<NavItem[]>(() => [
    { title: 'My pull requests', href: route('dashboard'), icon: LayoutGrid, badge: page.props.counts.awaiting_review },
    { title: 'Repositories', href: route('repositories.index'), icon: FolderGit2 },
    { title: 'Inbox', href: route('inbox.index'), icon: Inbox, badge: page.props.counts.unread_inbox },
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
