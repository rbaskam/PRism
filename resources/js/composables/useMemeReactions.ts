import { pickMeme, type Meme, type MemeMoment } from '@/lib/memes';
import { useStorage } from '@vueuse/core';
import { ref } from 'vue';

interface ShownMeme {
    /** Changes every time a reaction is shown, so the same meme twice in a row still restarts its timer. */
    id: number;
    meme: Meme;
}

/**
 * Off by default: the GIFs load from Giphy, and not everyone wants memes at work. Like the theme, it's remembered on
 * this computer (Settings → Appearance).
 */
const enabled = useStorage('prism.meme-reactions', false);

/** Shared by every component, so a reaction survives the page reloading its props after a review is submitted. */
const current = ref<ShownMeme | null>(null);

let nextId = 1;

/**
 * A meme and a funny line after the user approves, requests changes, comments or starts "Fix with Claude", when
 * meme reactions are turned on. `MemeReaction` shows it.
 */
export function useMemeReactions() {
    /** Shows a random reaction for the moment, if meme reactions are on. */
    function celebrate(moment: MemeMoment): void {
        if (!enabled.value) {
            return;
        }

        current.value = { id: nextId++, meme: pickMeme(moment) };
    }

    function dismiss(): void {
        current.value = null;
    }

    return { enabled, current, celebrate, dismiss };
}
