<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import type { FindingStatus, ReviewFinding } from '@/types';
import { router } from '@inertiajs/vue3';
import { Check, ChevronRight, FlagOff, Sparkles, ThumbsDown } from 'lucide-vue-next';
import { ref } from 'vue';
import MarkdownText from './MarkdownText.vue';
import SeverityBadge from './SeverityBadge.vue';
import { pageVisit } from './utils';

/**
 * An AI finding shown under the line it is about in Files changed, so it can be read and triaged next to the code.
 * New findings start open; ones already triaged start collapsed.
 */
const props = defineProps<{
    finding: ReviewFinding;
}>();

const open = ref(props.finding.status === 'new');
const busy = ref(false);

const statusActions: { status: Exclude<FindingStatus, 'new'>; label: string; icon: typeof Check }[] = [
    { status: 'read', label: 'Mark read', icon: Check },
    { status: 'not_useful', label: 'Not useful', icon: ThumbsDown },
    { status: 'not_blocker', label: 'Not a blocker', icon: FlagOff },
];

/** Clicking the status a finding already has puts it back to "new", as in the AI findings tab. */
function toggleStatus(status: Exclude<FindingStatus, 'new'>): void {
    busy.value = true;

    router.patch(
        route('findings.update', { finding: props.finding.id }),
        { status: props.finding.status === status ? 'new' : status },
        pageVisit({ onFinish: () => (busy.value = false) }),
    );
}
</script>

<template>
    <Collapsible
        v-model:open="open"
        class="overflow-hidden rounded-lg border bg-card text-card-foreground"
        :data-finding-id="finding.id"
        :class="{ 'opacity-80': finding.status !== 'new' && !open }"
    >
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 px-3 py-2" :class="{ 'border-b': open }">
            <CollapsibleTrigger as-child>
                <button type="button" class="flex min-w-0 grow basis-48 items-center gap-2 text-left">
                    <ChevronRight class="size-4 shrink-0 text-muted-foreground transition-transform" :class="{ 'rotate-90': open }" />
                    <Sparkles class="size-3.5 shrink-0 text-violet-500" />
                    <SeverityBadge :severity="finding.severity" />
                    <span class="min-w-0 truncate text-sm font-medium">{{ finding.title }}</span>
                </button>
            </CollapsibleTrigger>

            <div class="ml-auto flex shrink-0 items-center gap-1">
                <span class="mr-1 text-xs text-muted-foreground">{{ finding.category_label }}</span>
                <Badge v-if="finding.status !== 'new'" variant="muted" class="px-1.5 text-[10px]">{{ finding.status_label }}</Badge>
                <Button
                    v-for="action in statusActions"
                    :key="action.status"
                    variant="ghost"
                    size="icon"
                    class="size-7 [&_svg]:size-3.5"
                    :class="{ 'bg-muted text-foreground': finding.status === action.status }"
                    :aria-pressed="finding.status === action.status"
                    :aria-label="action.label"
                    :title="finding.status === action.status ? `${action.label} (click to undo)` : action.label"
                    :disabled="busy"
                    @click="toggleStatus(action.status)"
                >
                    <component :is="action.icon" />
                </Button>
            </div>
        </div>

        <CollapsibleContent>
            <div class="space-y-3 px-3 py-3">
                <MarkdownText :text="finding.body" />

                <div v-if="finding.suggestion" class="space-y-1">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Suggested fix</p>
                    <pre class="overflow-x-auto whitespace-pre-wrap rounded-md border bg-muted/50 p-3 font-mono text-xs leading-relaxed">{{
                        finding.suggestion
                    }}</pre>
                </div>
            </div>
        </CollapsibleContent>
    </Collapsible>
</template>
