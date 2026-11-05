<script setup lang="ts">
import { SidebarGroup, SidebarGroupAction, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { sendJson } from '@/lib/http';
import { closedStateLabel, pullRequestStateIcon } from '@/lib/pull-requests';
import type { RecentPullRequest, SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * The pull requests the user opened most recently, so they can get back to ones they started reviewing without
 * searching for them again. Hidden while the sidebar is collapsed to icons, where they'd all look the same.
 */

const page = usePage<SharedData>();
const pullRequests = computed(() => page.props.recentPullRequests ?? []);
const clearing = ref(false);

function isActive(pullRequest: RecentPullRequest): boolean {
    const path = new URL(route('pull-requests.show', { pullRequest: pullRequest.id }), window.location.origin).pathname;

    return page.url.split(/[?#]/)[0] === path;
}

/** "Merged", "Closed" or "Draft", read out after the reference since the icon alone shows it. */
function stateLabel(pullRequest: RecentPullRequest): string | null {
    return closedStateLabel(pullRequest)?.label ?? (pullRequest.is_draft ? 'Draft' : null);
}

/**
 * Clear the list, then reload only the list: reloading the whole page while on a pull request would count as viewing
 * it again and put it straight back.
 */
async function clear(): Promise<void> {
    clearing.value = true;

    try {
        await sendJson('DELETE', route('recent-pull-requests.destroy'));
    } catch {
        // The list stays as it was, which shows it wasn't cleared.
    }

    router.reload({ only: ['recentPullRequests'], onFinish: () => (clearing.value = false) });
}
</script>

<template>
    <SidebarGroup v-if="pullRequests.length" class="group-data-[collapsible=icon]:hidden" data-recent-pull-requests>
        <SidebarGroupLabel>Recently viewed</SidebarGroupLabel>
        <SidebarGroupAction
            as="button"
            type="button"
            :disabled="clearing"
            aria-label="Clear recently viewed pull requests"
            title="Clear recently viewed"
            class="text-sidebar-foreground/70 disabled:opacity-50"
            @click="clear"
        >
            <X />
        </SidebarGroupAction>
        <SidebarMenu>
            <SidebarMenuItem v-for="pullRequest in pullRequests" :key="pullRequest.id">
                <SidebarMenuButton as-child class="h-auto py-1.5" :is-active="isActive(pullRequest)">
                    <Link
                        :href="route('pull-requests.show', { pullRequest: pullRequest.id })"
                        :title="`${pullRequest.title} (${pullRequest.reference})`"
                        :aria-current="isActive(pullRequest) ? 'page' : undefined"
                    >
                        <component :is="pullRequestStateIcon(pullRequest)" class="mt-0.5 self-start text-muted-foreground" aria-hidden="true" />
                        <span class="flex min-w-0 flex-col">
                            <span class="truncate">{{ pullRequest.title }}</span>
                            <span class="truncate text-xs font-normal text-muted-foreground">
                                {{ pullRequest.reference }}<template v-if="stateLabel(pullRequest)"> · {{ stateLabel(pullRequest) }}</template>
                            </span>
                        </span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
