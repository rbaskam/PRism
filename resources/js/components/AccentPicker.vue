<script setup lang="ts">
import { useAppearance, type Accent } from '@/composables/useAppearance';
import { Check } from 'lucide-vue-next';

interface Props {
    class?: string;
}

const { class: containerClass = '' } = defineProps<Props>();

const { accent, updateAccent } = useAppearance();

/** The neutral swatch flips with the mode, so its tick does too; the coloured ones keep a white tick. */
const accents: { value: Accent; label: string; swatch: string }[] = [
    { value: 'neutral', label: 'Neutral', swatch: 'bg-neutral-900 text-white dark:bg-neutral-100 dark:text-neutral-900' },
    { value: 'blue', label: 'Blue', swatch: 'bg-blue-600 dark:bg-blue-500 text-white' },
    { value: 'green', label: 'Green', swatch: 'bg-green-600 dark:bg-green-500 text-white' },
    { value: 'violet', label: 'Violet', swatch: 'bg-violet-600 text-white' },
    { value: 'rose', label: 'Rose', swatch: 'bg-rose-600 text-white' },
    { value: 'orange', label: 'Orange', swatch: 'bg-orange-500 dark:bg-orange-600 text-white' },
];
</script>

<template>
    <div role="radiogroup" aria-label="Accent colour" :class="['flex flex-wrap gap-2', containerClass]">
        <button
            v-for="{ value, label, swatch } in accents"
            :key="value"
            type="button"
            role="radio"
            :aria-checked="accent === value"
            @click="updateAccent(value)"
            :class="[
                'flex items-center gap-2 rounded-md border px-3 py-1.5 text-sm transition-colors',
                accent === value ? 'border-foreground/40 bg-muted font-medium' : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground',
            ]"
        >
            <span :class="['flex size-4 items-center justify-center rounded-full', swatch]">
                <Check v-if="accent === value" class="size-3" :stroke-width="3" />
            </span>
            {{ label }}
        </button>
    </div>
</template>
