<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import FlashMessages from '@/components/FlashMessages.vue';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { Check } from 'lucide-vue-next';

const props = defineProps<{
    /** 1 = GitHub, 2 = Repositories, 3 = Claude */
    step: 1 | 2 | 3;
    title: string;
    description?: string;
}>();

const page = usePage<SharedData>();

const steps = ['Connect GitHub', 'Choose repositories', 'Connect Claude'];
</script>

<template>
    <div class="flex min-h-svh flex-col items-center bg-muted/40 px-4 py-10">
        <div class="w-full max-w-3xl">
            <div class="mb-8 flex items-center gap-3">
                <AppLogoIcon class="size-9" />
                <span class="text-lg font-semibold">{{ page.props.name }}</span>
            </div>

            <ol class="mb-8 grid grid-cols-3 gap-2">
                <li v-for="(label, index) in steps" :key="label" class="flex flex-col gap-2">
                    <div class="h-1.5 rounded-full" :class="index + 1 <= props.step ? 'bg-primary' : 'bg-border'" />
                    <div
                        class="flex items-center gap-1.5 text-xs"
                        :class="index + 1 === props.step ? 'font-medium text-foreground' : 'text-muted-foreground'"
                    >
                        <Check v-if="index + 1 < props.step" class="size-3.5 text-emerald-600" />
                        <span>{{ index + 1 }}. {{ label }}</span>
                    </div>
                </li>
            </ol>

            <div class="rounded-xl border bg-background p-6 shadow-sm sm:p-8">
                <h1 class="text-2xl font-semibold tracking-tight">{{ props.title }}</h1>
                <p v-if="props.description" class="mt-1 text-sm text-muted-foreground">{{ props.description }}</p>
                <div class="mt-6">
                    <slot />
                </div>
            </div>
        </div>
        <FlashMessages />
    </div>
</template>
