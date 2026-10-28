<script setup lang="ts">
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { CheckCircle2, X, XCircle } from 'lucide-vue-next';
import { ref, watch } from 'vue';

interface Toast {
    id: number;
    type: 'success' | 'error';
    message: string;
}

const page = usePage<SharedData>();
const toasts = ref<Toast[]>([]);
let nextId = 1;

function dismiss(id: number): void {
    toasts.value = toasts.value.filter((toast) => toast.id !== id);
}

function push(type: Toast['type'], message: string | null): void {
    if (!message) {
        return;
    }

    const id = nextId++;
    toasts.value.push({ id, type, message });
    setTimeout(() => dismiss(id), type === 'error' ? 8000 : 4000);
}

/*
 * Only a response that carries flash messages brings a new flash object. Background reloads (polls,
 * deferred props) keep the previous one, so watching the object itself, not deeply, shows each
 * message once instead of again on every poll.
 */
watch(
    () => page.props.flash,
    (flash, previous) => {
        if (flash === previous) {
            return;
        }

        push('success', flash?.success ?? null);
        push('error', flash?.error ?? null);
    },
    { immediate: true },
);
</script>

<template>
    <div class="pointer-events-none fixed bottom-4 right-4 z-50 flex w-full max-w-sm flex-col gap-2">
        <TransitionGroup
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="translate-y-2 opacity-0"
            leave-active-class="transition duration-150 ease-in"
            leave-to-class="opacity-0"
        >
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="pointer-events-auto flex items-start gap-3 rounded-lg border bg-background p-3 text-sm shadow-lg"
                :class="toast.type === 'error' ? 'border-red-300 dark:border-red-500/40' : 'border-emerald-300 dark:border-emerald-500/40'"
                :role="toast.type === 'error' ? 'alert' : 'status'"
            >
                <CheckCircle2 v-if="toast.type === 'success'" class="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-500" />
                <XCircle v-else class="mt-0.5 size-4 shrink-0 text-red-600 dark:text-red-500" />
                <p class="flex-1">{{ toast.message }}</p>
                <button type="button" class="rounded text-muted-foreground hover:text-foreground" @click="dismiss(toast.id)">
                    <X class="size-4" />
                    <span class="sr-only">Dismiss</span>
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
