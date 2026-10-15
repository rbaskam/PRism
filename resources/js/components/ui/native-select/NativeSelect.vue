<script setup lang="ts">
import { cn } from '@/lib/utils';
import { useVModel } from '@vueuse/core';
import { ChevronDown } from 'lucide-vue-next';
import type { HTMLAttributes } from 'vue';

/*
 * `class` sizes the wrapper; every other attribute (id, aria-label, name, disabled…) belongs on the <select>,
 * so labels and screen readers find it.
 */
defineOptions({ inheritAttrs: false });

const props = defineProps<{
    modelValue?: string | number | null;
    class?: HTMLAttributes['class'];
}>();

const emits = defineEmits<{
    (e: 'update:modelValue', payload: string | number | null): void;
}>();

const modelValue = useVModel(props, 'modelValue', emits, { passive: true });
</script>

<template>
    <div :class="cn('relative', props.class)">
        <select
            v-model="modelValue"
            v-bind="$attrs"
            class="flex h-9 w-full appearance-none rounded-md border border-input bg-background py-1 pl-3 pr-8 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
        >
            <slot />
        </select>
        <ChevronDown class="pointer-events-none absolute right-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
    </div>
</template>
