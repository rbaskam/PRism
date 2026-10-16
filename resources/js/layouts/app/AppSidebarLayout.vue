<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import ContainerNotice from '@/components/ContainerNotice.vue';
import FlashMessages from '@/components/FlashMessages.vue';
import WhatsNewDialog from '@/components/WhatsNewDialog.vue';
import { useActivityHeartbeat } from '@/composables/useActivityHeartbeat';
import type { BreadcrumbItemType } from '@/types';
import { usePoll } from '@inertiajs/vue3';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

// Keep the sidebar badges (awaiting reviews, unread inbox, running AI reviews) fresh.
usePoll(30000, { only: ['counts'] });

// Clicking, typing and scrolling count as using the app, so the app lock doesn't kick in while it's in use.
useActivityHeartbeat();
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar">
            <AppSidebarHeader :breadcrumbs="breadcrumbs">
                <template #actions>
                    <slot name="header-actions" />
                </template>
            </AppSidebarHeader>
            <ContainerNotice />
            <slot />
        </AppContent>
        <FlashMessages />
        <WhatsNewDialog />
    </AppShell>
</template>
