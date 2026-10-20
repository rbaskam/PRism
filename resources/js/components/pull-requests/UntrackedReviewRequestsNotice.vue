<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/vue3';
import { useStorage } from '@vueuse/core';
import { BellRing, X } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    /** "owner/name" repositories with review requests for the user that aren't tracked. */
    repositories: string[];
}>();

const MAX_LISTED = 5;

/** Dismissing hides the repositories listed right now; a new untracked review request brings the notice back. */
const dismissedRepositories = useStorage<string[]>('prism:dismissed-untracked-review-requests', []);

const pendingRepositories = computed(() => props.repositories.filter((repository) => !dismissedRepositories.value.includes(repository)));
const listedRepositories = computed(() => pendingRepositories.value.slice(0, MAX_LISTED));
const unlistedCount = computed(() => pendingRepositories.value.length - listedRepositories.value.length);

function dismiss(): void {
    dismissedRepositories.value = [...new Set([...dismissedRepositories.value, ...props.repositories])];
}
</script>

<template>
    <div
        v-if="pendingRepositories.length > 0"
        role="status"
        class="flex flex-col gap-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-500/30 dark:bg-amber-500/10 sm:flex-row sm:items-start"
    >
        <div class="flex min-w-0 flex-1 gap-3">
            <BellRing class="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
            <div class="min-w-0 space-y-2">
                <div>
                    <p class="font-medium text-amber-950 dark:text-amber-100">
                        You have review requests in {{ pendingRepositories.length }}
                        {{ pendingRepositories.length === 1 ? "repository you aren't" : "repositories you aren't" }} tracking
                    </p>
                    <p class="text-amber-900/80 dark:text-amber-100/70">
                        Track {{ pendingRepositories.length === 1 ? 'it' : 'them' }} to see those pull requests here.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-1.5">
                    <span
                        v-for="repository in listedRepositories"
                        :key="repository"
                        class="rounded border border-amber-300/80 bg-background/70 px-1.5 font-mono text-xs leading-5 text-foreground dark:border-amber-500/30"
                    >
                        {{ repository }}
                    </span>
                    <span v-if="unlistedCount > 0" class="text-xs text-amber-900/80 dark:text-amber-100/70">and {{ unlistedCount }} more</span>
                </div>
            </div>
        </div>
        <div class="flex shrink-0 items-center gap-1 pl-7 sm:pl-0">
            <Button as-child size="sm" variant="outline" class="h-8 bg-background/70">
                <Link :href="route('settings.github')">Track repositories</Link>
            </Button>
            <Button
                size="icon"
                variant="ghost"
                class="size-8 text-amber-900/70 hover:bg-amber-100 dark:text-amber-100/70 dark:hover:bg-amber-500/15"
                @click="dismiss"
            >
                <X />
                <span class="sr-only">Dismiss</span>
            </Button>
        </div>
    </div>
</template>
