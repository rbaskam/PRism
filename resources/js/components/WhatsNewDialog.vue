<script setup lang="ts">
import MarkdownText from '@/components/pull-request/MarkdownText.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { Sparkles } from 'lucide-vue-next';
import { ref, watch } from 'vue';

/**
 * Shows the changelog for the version the user just updated to, once. Closing it in any way marks the version as seen.
 */
const page = usePage<SharedData>();

const open = ref(false);
const dismissedVersion = ref<string | null>(null);

watch(
    () => page.props.whatsNew?.version,
    (version) => {
        if (version && version !== dismissedVersion.value) {
            open.value = true;
        }
    },
    { immediate: true },
);

watch(open, (isOpen, wasOpen) => {
    if (wasOpen && !isOpen) {
        dismiss();
    }
});

/** Returns false when this version was already dismissed. */
function markDismissed(): boolean {
    const version = page.props.whatsNew?.version;

    if (!version || dismissedVersion.value === version) {
        return false;
    }

    dismissedVersion.value = version;

    return true;
}

function dismiss(onFinish?: () => void): void {
    if (!markDismissed()) {
        onFinish?.();

        return;
    }

    router.post(route('whats-new.dismiss'), {}, { preserveScroll: true, preserveState: true, onFinish });
}

/** Dismiss first, then navigate, so the two requests don't cancel each other. */
function seeFullChangelog(): void {
    dismiss(() => router.visit(route('settings.about')));
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent v-if="page.props.whatsNew" class="flex max-h-[85vh] max-w-xl flex-col">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <Sparkles class="size-5 text-violet-500" />
                    What's new in {{ page.props.name }} v{{ page.props.whatsNew.version }}
                </DialogTitle>
                <DialogDescription>You're now running version {{ page.props.whatsNew.version }}. Here's what changed.</DialogDescription>
            </DialogHeader>

            <div class="-mx-6 min-h-0 flex-1 overflow-y-auto border-y px-6 py-4">
                <MarkdownText :text="page.props.whatsNew.notes" />
            </div>

            <DialogFooter class="items-center gap-2 sm:justify-between">
                <Button variant="link" class="px-0" @click="seeFullChangelog">See the full changelog</Button>
                <Button @click="open = false">Got it</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
