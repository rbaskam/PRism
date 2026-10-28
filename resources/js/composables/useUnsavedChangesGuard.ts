import type { PendingVisit } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, toValue, type MaybeRefOrGetter } from 'vue';

export const UNSAVED_CHANGES_MESSAGE = 'You have unsaved changes. Leave without saving?';

/** Every guarded form on the page. One listener asks once, however many of them have changes. */
const dirtyChecks = new Set<() => boolean>();
let stopListening: VoidFunction | null = null;

/**
 * Whether a visit leaves the page: a GET to another page. Saving a form (PUT, POST…), polls and partial reloads
 * (`only`/`except`), prefetches and reloads of the same page all keep the form where it is.
 */
function leavesThePage(visit: PendingVisit): boolean {
    return (
        visit.method === 'get' &&
        !visit.prefetch &&
        visit.only.length === 0 &&
        visit.except.length === 0 &&
        visit.url.pathname !== window.location.pathname
    );
}

/**
 * Ask before an Inertia visit takes the user away from a form with unsaved changes, e.g. a click in the sidebar
 * after flipping a switch in Settings. Only in-app visits are guarded: `beforeunload` would silently stop the
 * desktop window from closing in Electron.
 */
export function useUnsavedChangesGuard(isDirty: MaybeRefOrGetter<boolean>): void {
    const check = (): boolean => toValue(isDirty);

    dirtyChecks.add(check);

    stopListening ??= router.on('before', (event) => {
        if (!leavesThePage(event.detail.visit) || ![...dirtyChecks].some((hasChanges) => hasChanges())) {
            return;
        }

        return window.confirm(UNSAVED_CHANGES_MESSAGE);
    });

    onBeforeUnmount(() => {
        dirtyChecks.delete(check);

        if (dirtyChecks.size === 0) {
            stopListening?.();
            stopListening = null;
        }
    });
}
