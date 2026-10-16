import { postJson } from '@/lib/http';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { useEventListener, useThrottleFn } from '@vueuse/core';

/** How often, at most, the server hears that the user is active. Well under the shortest auto-lock (1 minute). */
const HEARTBEAT_INTERVAL_MS = 30_000;

/**
 * Tells the server the app is in use while the user clicks, types, scrolls or moves the mouse, so the app lock's
 * auto-lock only counts the time they actually spend away. Page loads and saves count on their own, but reading a
 * long diff or writing a question for Claude sends nothing to the server, which used to lock the app mid-sentence.
 */
export function useActivityHeartbeat(): void {
    const page = usePage<SharedData>();

    const signalActivity = useThrottleFn(
        () => {
            if (page.props.appLock?.enabled) {
                postJson(route('activity.store')).catch(() => undefined);
            }
        },
        HEARTBEAT_INTERVAL_MS,
        false,
        true,
    );

    for (const event of ['pointerdown', 'keydown', 'wheel', 'mousemove', 'touchstart'] as const) {
        useEventListener(window, event, signalActivity, { passive: true });
    }
}
