<script setup lang="ts">
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { useStorage } from '@vueuse/core';
import { Container, X } from 'lucide-vue-next';

const page = usePage<SharedData>();
const dismissed = useStorage('prism:container-notice-dismissed', false);
</script>

<template>
    <div
        v-if="page.props.isContainer && !dismissed"
        class="mx-4 mt-4 flex items-start gap-3 rounded-lg border border-sky-300 bg-sky-50 p-3 text-sm text-sky-900 dark:border-sky-500/40 dark:bg-sky-500/10 dark:text-sky-200"
    >
        <Container class="mt-0.5 size-4 shrink-0" />
        <p class="flex-1">
            You're using the web version inside Docker (Sail). The container can't see your Mac's <code class="font-mono">claude</code> and
            <code class="font-mono">gh</code> commands, your local checkouts or desktop notifications, so AI reviews and code changes won't run here.
            Start the desktop app with <code class="rounded bg-sky-100 px-1 font-mono dark:bg-sky-500/20">composer native:dev</code>.
        </p>
        <button type="button" class="text-sky-700 hover:text-sky-900 dark:text-sky-300" @click="dismissed = true">
            <X class="size-4" />
            <span class="sr-only">Dismiss</span>
        </button>
    </div>
</template>
