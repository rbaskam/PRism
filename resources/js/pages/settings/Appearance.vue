<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import AccentPicker from '@/components/AccentPicker.vue';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { type BreadcrumbItem } from '@/types';

import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Appearance settings',
        href: '/settings/appearance',
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Appearance settings" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall title="Appearance settings" description="Choose how the app looks on this computer." />

                <div class="space-y-3">
                    <p class="text-sm font-medium">Mode</p>
                    <AppearanceTabs />
                </div>

                <div class="space-y-3">
                    <div class="space-y-1">
                        <p class="text-sm font-medium">Accent colour</p>
                        <p class="text-sm text-muted-foreground">Used for buttons, switches, focus rings and the selected item in the sidebar.</p>
                    </div>
                    <AccentPicker />
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
