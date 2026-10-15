<script setup lang="ts">
import ClaudeConnectionForm, { type ClaudeConnectionSettings } from '@/components/ClaudeConnectionForm.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { BreadcrumbItem, ClaudeAuthMode, ClaudeStatus } from '@/types';
import { Head } from '@inertiajs/vue3';

defineProps<{
    status: ClaudeStatus;
    settings: ClaudeConnectionSettings & { has_credential: boolean };
    authModes: { value: ClaudeAuthMode; label: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Claude settings', href: route('settings.claude') }];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Claude settings" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    title="Claude Code"
                    description="AI reviews run Claude Code on this computer with read-only tools. It never writes to a pull request by itself."
                />

                <ClaudeConnectionForm :status="status" :settings="settings" :auth-modes="authModes" />
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
