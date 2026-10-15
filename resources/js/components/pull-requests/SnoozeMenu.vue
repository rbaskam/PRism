<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatSnoozedUntil } from '@/lib/pull-requests';
import type { PullRequest } from '@/types';
import type { VisitOptions } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import { AlarmClock, AlarmClockOff } from 'lucide-vue-next';

/**
 * Snooze a pull request for a while: it leaves your lists and counts until the snooze ends, or until new commits are
 * pushed to it. The snooze is kept in PRism only; nothing is sent to GitHub.
 */
const props = withDefaults(
    defineProps<{
        pullRequest: PullRequest;
        /** An icon-only trigger, for pull request rows. */
        compact?: boolean;
        /** Extra options for the visit, e.g. pageVisit() on the pull request page. */
        visitOptions?: VisitOptions;
    }>(),
    { compact: false, visitOptions: () => ({}) },
);

const durations = [
    { days: 1, label: 'For 1 day' },
    { days: 3, label: 'For 3 days' },
    { days: 7, label: 'For 1 week' },
];

function snooze(days: number): void {
    router.post(
        route('pull-requests.snooze.store', { pullRequest: props.pullRequest.id }),
        { days },
        { preserveScroll: true, ...props.visitOptions },
    );
}

function unsnooze(): void {
    router.delete(route('pull-requests.snooze.destroy', { pullRequest: props.pullRequest.id }), { preserveScroll: true, ...props.visitOptions });
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                v-if="compact"
                variant="ghost"
                size="icon"
                class="size-6 text-muted-foreground opacity-0 transition-opacity hover:text-foreground focus-visible:opacity-100 group-hover:opacity-100 data-[state=open]:opacity-100"
                :title="pullRequest.snoozed_until ? 'Change or end the snooze' : 'Snooze'"
            >
                <AlarmClock class="size-3.5" />
                <span class="sr-only">Snooze</span>
            </Button>
            <Button v-else variant="outline" size="sm">
                <AlarmClock />
                {{ pullRequest.snoozed_until ? 'Snoozed' : 'Snooze' }}
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-64">
            <DropdownMenuLabel class="space-y-0.5 font-normal">
                <p class="font-medium">
                    {{ pullRequest.snoozed_until ? `Snoozed until ${formatSnoozedUntil(pullRequest.snoozed_until)}` : 'Snooze this pull request' }}
                </p>
                <p class="text-xs text-muted-foreground">Hidden from your lists and counts until then, or until new commits are pushed.</p>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem v-for="duration in durations" :key="duration.days" @select="snooze(duration.days)">
                <AlarmClock />
                {{ duration.label }}
            </DropdownMenuItem>
            <template v-if="pullRequest.snoozed_until">
                <DropdownMenuSeparator />
                <DropdownMenuItem @select="unsnooze">
                    <AlarmClockOff />
                    Unsnooze
                </DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
