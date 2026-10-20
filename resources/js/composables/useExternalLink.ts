import { postJson } from '@/lib/http';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';

/**
 * Opens links in the system browser. Inside the desktop app this goes through NativePHP's Shell,
 * because the app window should never navigate away to GitHub. That is a plain request rather than an
 * Inertia visit, so the page isn't reloaded (a reload drops props loaded on demand, like the changed files).
 */
export function useExternalLink() {
    const page = usePage<SharedData>();

    function openExternal(url: string | null | undefined): void {
        if (!url) {
            return;
        }

        if (page.props.isNative) {
            postJson(route('external-links.store'), { url }, 'Could not open the link').catch((error) => console.error(error));

            return;
        }

        window.open(url, '_blank', 'noopener');
    }

    return { openExternal };
}
