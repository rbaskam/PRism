import { ref } from 'vue';

/** Shared, so the sidebar's Search button can open the switcher that the layout mounts. */
const isOpen = ref(false);

/** "⌘K" on macOS, "Ctrl+K" on Windows. */
export const quickSwitcherShortcut = navigator.platform.toLowerCase().includes('mac') ? '⌘K' : 'Ctrl+K';

/** Whether the ⌘K quick switcher (go to a page, repository or pull request) is open. */
export function useQuickSwitcher() {
    return {
        isOpen,
        open: (): void => {
            isOpen.value = true;
        },
        toggle: (): void => {
            isOpen.value = !isOpen.value;
        },
    };
}
