<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { LucideIcon } from 'lucide-vue-next';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{
    icon: LucideIcon;
    title: string;
    description?: string;
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <div :class="cn('flex flex-col items-center justify-center gap-4 px-6 py-14 text-center', props.class)">
        <div class="flex size-11 items-center justify-center rounded-full bg-muted">
            <component :is="icon" class="size-5 text-muted-foreground" />
        </div>
        <div class="max-w-md space-y-1">
            <h3 class="text-sm font-semibold">{{ title }}</h3>
            <p v-if="description" class="text-sm text-muted-foreground">{{ description }}</p>
            <slot name="description" />
        </div>
        <div v-if="$slots.default" class="flex flex-wrap items-center justify-center gap-2">
            <slot />
        </div>
    </div>
</template>
