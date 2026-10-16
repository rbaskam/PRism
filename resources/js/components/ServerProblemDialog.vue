<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { postJson } from '@/lib/http';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { Loader2, RotateCcw, ServerCrash } from 'lucide-vue-next';
import { onMounted, onUnmounted, ref } from 'vue';

/**
 * In the desktop app, a request that gets no answer, or an answer that isn't from PRism, means the app's server has
 * stopped: NativePHP doesn't restart it, and another program on the same port may answer instead. Rather than
 * Inertia's raw error page, explain that and offer to restart PRism. When the server can't take that request either,
 * the scheduler's watchdog relaunches the app within a minute.
 */
const page = usePage<SharedData>();

const open = ref(false);
const restarting = ref(false);
const unreachable = ref(false);

function takeOver(event: Event): boolean {
    if (!page.props.recoversFromServerProblems) {
        return true;
    }

    event.preventDefault();
    unreachable.value = false;
    open.value = true;

    return false;
}

const stopListening: (() => void)[] = [];

onMounted(() => {
    stopListening.push(router.on('invalid', takeOver), router.on('exception', takeOver));
});

onUnmounted(() => stopListening.forEach((stop) => stop()));

async function restart(): Promise<void> {
    restarting.value = true;

    try {
        const result = await postJson<{ restarting: boolean }>(route('app.restart'), {}, "Couldn't restart PRism");

        // PRism relaunches in a moment; until then, keep showing that it's restarting.
        restarting.value = result.restarting;
    } catch {
        restarting.value = false;
        unreachable.value = true;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-md" @interact-outside.prevent>
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <ServerCrash class="size-5 text-red-600 dark:text-red-400" />
                    {{ page.props.name }} stopped responding
                </DialogTitle>
                <DialogDescription>
                    {{ page.props.name }} runs a small server in the background, and it isn't answering properly. Restarting
                    {{ page.props.name }} fixes this.
                </DialogDescription>
            </DialogHeader>

            <p
                v-if="unreachable"
                class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
                role="status"
            >
                The server can't take that request either, so {{ page.props.name }} will restart by itself within a minute. If it doesn't, quit it and
                open it again.
            </p>

            <DialogFooter class="gap-2">
                <Button variant="outline" @click="open = false">Close</Button>
                <Button :disabled="restarting" @click="restart">
                    <Loader2 v-if="restarting" class="animate-spin" />
                    <RotateCcw v-else />
                    {{ restarting ? 'Restarting…' : `Restart ${page.props.name}` }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
