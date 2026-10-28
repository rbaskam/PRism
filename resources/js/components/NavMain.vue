<script setup lang="ts">
import { SidebarGroup, SidebarMenu, SidebarMenuBadge, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';

defineProps<{
    items: NavItem[];
}>();

const page = usePage();

function isActive(item: NavItem): boolean {
    const path = new URL(item.href, window.location.origin).pathname;

    return path === '/' ? page.url === '/' || page.url.startsWith('/?') : page.url.startsWith(path);
}

/** "3 awaiting your review" */
function countLabel(item: NavItem): string {
    return [item.badge, item.badgeLabel].filter(Boolean).join(' ');
}
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    class="relative"
                    :is-active="isActive(item)"
                    :tooltip="item.badge ? `${item.title} (${countLabel(item)})` : item.title"
                >
                    <Link :href="item.href">
                        <component :is="item.icon" />
                        <!-- The badge is hidden while the sidebar is collapsed to icons, so a dot on the icon stands in for it. -->
                        <span
                            v-if="item.badge"
                            data-collapsed-badge
                            class="pointer-events-none absolute right-1 top-1 hidden size-2 rounded-full bg-red-500 ring-2 ring-sidebar group-data-[collapsible=icon]:block"
                        >
                            <span class="sr-only">{{ countLabel(item) }}</span>
                        </span>
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
                <SidebarMenuBadge v-if="item.badge" class="bg-red-500/90 text-white peer-data-[active=true]/menu-button:text-white">
                    {{ item.badge }}
                </SidebarMenuBadge>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
