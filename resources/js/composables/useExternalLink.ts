import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';

/**
 * Opens links in the system browser. Inside the desktop app this goes through NativePHP's Shell,
 * because the app window should never navigate away to GitHub.
 */
export function useExternalLink() {
    const page = usePage<SharedData>();

    function openExternal(url: string | null | undefined): void {
        if (!url) {
            return;
        }

        if (page.props.isNative) {
            router.post(route('external-links.store'), { url }, { preserveScroll: true, preserveState: true });

            return;
        }

        window.open(url, '_blank', 'noopener');
    }

    return { openExternal };
}
