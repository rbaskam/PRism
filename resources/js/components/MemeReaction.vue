<script setup lang="ts">
import { useMemeReactions } from '@/composables/useMemeReactions';
import { memeImageUrl } from '@/lib/memes';
import { X } from 'lucide-vue-next';
import { onBeforeUnmount, ref, watch } from 'vue';

/** Long enough to watch the GIF loop once or twice and read the line. */
const DISPLAY_MS = 7000;

/**
 * The meme reaction from `useMemeReactions`, in the corner with the flash messages. It hides itself after a few
 * seconds; if the GIF can't load (offline), the funny line still shows.
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
        leave-active-class="transition duration-150 ease-in"
        leave-to-class="opacity-0"
    >
        <figure
            v-if="current"
            :key="current.id"
            role="status"
            data-meme-reaction
            class="pointer-events-auto overflow-hidden rounded-lg border bg-background text-sm shadow-lg"
        >
            <div class="relative">
                <img
                    v-if="!imageFailed"
                    :src="memeImageUrl(current.meme)"
                    :alt="current.meme.alt"
                    class="block max-h-72 min-h-40 w-full bg-muted object-contain"
                    @error="imageFailed = true"
                />
                <button
                    type="button"
                    aria-label="Dismiss meme"
                    title="Dismiss"
                    :class="[
                        'rounded-full p-1 text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                        imageFailed ? 'float-right m-2' : 'absolute right-2 top-2 bg-background/80 backdrop-blur',
                    ]"
                    @click="dismiss"
                >
                    <X class="size-4" />
                </button>
            </div>
            <figcaption class="p-3 font-medium">{{ current.meme.caption }}</figcaption>
        </figure>
    </Transition>
</template>
