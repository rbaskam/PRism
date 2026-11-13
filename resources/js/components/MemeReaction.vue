<script setup lang="ts">
import { useMemeReactions } from '@/composables/useMemeReactions';
import { memeImageUrl } from '@/lib/memes';
import { onBeforeUnmount, ref, watch } from 'vue';

/** Long enough to read the line; at five seconds or less the GIF doesn't need a pause button. */
const DISPLAY_MS = 5000;

/**
 * The meme reaction from `useMemeReactions`: small, in the corner with the flash messages, and out of the way. Clicks
 * go straight through it, and it fades away on its own. If the GIF can't load (offline), the funny line still shows.
 */
const { current, dismiss } = useMemeReactions();

const imageFailed = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

watch(
    () => current.value?.id,
    (id) => {
        clearTimeout(timer);
        imageFailed.value = false;

        if (id !== undefined) {
            timer = setTimeout(dismiss, DISPLAY_MS);
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="translate-y-2 scale-95 opacity-0"
        leave-active-class="transition duration-500 ease-in"
        leave-to-class="translate-y-1 opacity-0"
    >
        <figure
            v-if="current"
            :key="current.id"
            role="status"
            data-meme-reaction
            class="pointer-events-none w-56 select-none self-end overflow-hidden rounded-lg border bg-background text-xs shadow-lg"
        >
            <img
                v-if="!imageFailed"
                :src="memeImageUrl(current.meme)"
                :alt="current.meme.alt"
                class="block max-h-36 min-h-20 w-full bg-muted object-contain"
                @error="imageFailed = true"
            />
            <figcaption class="px-2.5 py-2 font-medium">{{ current.caption }}</figcaption>
        </figure>
    </Transition>
</template>
