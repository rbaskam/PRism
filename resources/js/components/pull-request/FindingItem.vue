<script setup lang="ts">
import ExternalLink from '@/components/ExternalLink.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { findingStatusLabels, timeAgo } from '@/lib/pull-requests';
import { cn } from '@/lib/utils';
import type { FindingStatus, ReviewFinding } from '@/types';
import { Check, ChevronRight, ExternalLink as ExternalLinkIcon, FlagOff, RotateCcw, Send, ThumbsDown, Wand2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import MarkdownText from './MarkdownText.vue';
import SettingsHint from './SettingsHint.vue';
import SeverityBadge from './SeverityBadge.vue';
import { findingLocation } from './utils';

const props = defineProps<{
    finding: ReviewFinding;
    selected: boolean;
    busy: boolean;
    canPostFindings: boolean;
    /** "Fix with Claude" is available. */
    canChangeCode: boolean;
}>();

const emit = defineEmits<{
    'update:selected': [selected: boolean];
    status: [status: FindingStatus];
    post: [];
    fix: [];
}>();

const row = ref<HTMLElement | null>(null);
const expanded = ref(false);

const location = computed(() => findingLocation(props.finding));
const isNew = computed(() => props.finding.status === 'new' && !props.finding.is_resolved);
const isDimmed = computed(() => props.finding.is_resolved || props.finding.status === 'not_useful' || props.finding.status === 'not_blocker');
const canPost = computed(() => props.canPostFindings && !props.finding.is_posted);

const statusActions: { status: Exclude<FindingStatus, 'new'>; label: string; shortcut: string; icon: typeof Check }[] = [
    { status: 'read', label: 'Mark read', shortcut: 'r', icon: Check },
    { status: 'not_useful', label: 'Not useful', shortcut: 'u', icon: ThumbsDown },
    { status: 'not_blocker', label: 'Not a blocker', shortcut: 'b', icon: FlagOff },
];

/** Clicking the status a finding already has puts it back to "new". */
function toggleStatus(status: Exclude<FindingStatus, 'new'>): void {
    emit('status', props.finding.status === status ? 'new' : status);
}

function setStatus(status: FindingStatus): void {
    if (!props.busy && props.finding.status !== status) {
        emit('status', status);
    }
}

function post(): void {
    if (canPost.value) {
        emit('post');
    }
}

function fix(): void {
    if (props.canChangeCode && !props.finding.is_resolved) {
        emit('fix');
    }
}

function handleRowClick(event: MouseEvent): void {
    const target = event.target as HTMLElement;

    if (target.closest('button, a, input, textarea, [role="checkbox"]') || window.getSelection()?.toString()) {
        return;
    }

    expanded.value = !expanded.value;
}

function focusSibling(direction: 1 | -1): void {
    const rows = Array.from(document.querySelectorAll<HTMLElement>('[data-finding-row]'));
    const index = row.value ? rows.indexOf(row.value) : -1;

    rows[index + direction]?.focus();
}

/** Keyboard triage: decide, then continue with the next finding. */
function setStatusAndMoveOn(status: FindingStatus): void {
    setStatus(status);
    focusSibling(1);
}

/** Triage shortcuts while a finding row has focus. */
function handleKeydown(event: KeyboardEvent): void {
    if (event.target !== row.value || event.metaKey || event.ctrlKey || event.altKey) {
        return;
    }

    const actions: Record<string, () => void> = {
        Enter: () => (expanded.value = !expanded.value),
        ' ': () => (expanded.value = !expanded.value),
        o: () => (expanded.value = !expanded.value),
        j: () => focusSibling(1),
        ArrowDown: () => focusSibling(1),
        k: () => focusSibling(-1),
        ArrowUp: () => focusSibling(-1),
        x: () => emit('update:selected', !props.selected),
        r: () => setStatusAndMoveOn('read'),
        u: () => setStatusAndMoveOn('not_useful'),
        b: () => setStatusAndMoveOn('not_blocker'),
        n: () => setStatusAndMoveOn('new'),
        p: () => post(),
        f: () => fix(),
    };

    const action = actions[event.key];

    if (action) {
        event.preventDefault();
        action();
    }
}
</script>

<template>
    <div
        ref="row"
        data-finding-row
        tabindex="0"
        :class="
            cn(
                'group relative cursor-pointer border-b border-l-2 border-l-transparent outline-none transition-colors last:border-b-0 hover:bg-muted/40 focus-visible:bg-muted/50 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring',
                isNew && 'border-l-sky-500',
                selected && 'bg-primary/5',
            )
        "
        @click="handleRowClick"
        @keydown="handleKeydown"
    >
        <div class="flex items-start gap-2.5 px-3 py-2">
            <Checkbox
                :checked="selected"
                class="mt-0.5 size-4"
                :aria-label="`Select “${finding.title}”`"
                tabindex="-1"
                @update:checked="emit('update:selected', $event)"
            />
            <button
                type="button"
                tabindex="-1"
                class="mt-0.5 shrink-0 rounded text-muted-foreground hover:text-foreground"
                :aria-label="expanded ? 'Collapse finding' : 'Expand finding'"
                :aria-expanded="expanded"
                @click="expanded = !expanded"
            >
                <ChevronRight class="size-4 transition-transform" :class="{ 'rotate-90': expanded }" />
            </button>

            <div class="min-w-0 flex-1 space-y-1">
                <div class="flex items-start gap-2">
                    <SeverityBadge :severity="finding.severity" />
                    <span
                        :class="
                            cn(
                                'min-w-0 text-sm leading-5',
                                isNew ? 'font-semibold' : 'font-medium',
                                isDimmed && 'text-muted-foreground',
                                finding.is_resolved && 'line-through decoration-muted-foreground/50',
                            )
                        "
                    >
                        {{ finding.title }}
                    </span>
                </div>
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                    <span>{{ finding.category_label }}</span>
                    <span v-if="location" class="max-w-full truncate font-mono text-foreground/70" :title="location">{{ location }}</span>
                    <span v-else class="italic">General</span>
                    <Badge v-if="isNew" variant="info" class="px-1.5 py-0 text-[10px]">New</Badge>
                    <Badge v-else-if="finding.status !== 'new'" variant="muted" class="px-1.5 py-0 text-[10px]">
                        {{ findingStatusLabels[finding.status] }}
                    </Badge>
                    <Badge v-if="finding.is_resolved" variant="success" class="px-1.5 py-0 text-[10px]">Resolved in latest run</Badge>
                    <ExternalLink
                        v-if="finding.is_posted"
                        :href="finding.github_comment_url"
                        class="inline-flex items-center gap-1 font-medium text-foreground/80 underline-offset-2 hover:underline"
                    >
                        <ExternalLinkIcon class="size-3" />
                        On GitHub
                    </ExternalLink>
                </div>
            </div>

            <div class="hidden shrink-0 items-center gap-0.5 sm:flex" :class="{ 'pointer-events-none opacity-50': busy }">
                <Button
                    v-for="action in statusActions"
                    :key="action.status"
                    variant="ghost"
                    size="icon"
                    tabindex="-1"
                    :class="
                        cn(
                            'size-7 text-muted-foreground [&_svg]:size-3.5',
                            finding.status === action.status && 'bg-secondary text-foreground hover:bg-secondary',
                        )
                    "
                    :aria-pressed="finding.status === action.status"
                    :title="finding.status === action.status ? `${action.label} — click to reset to new` : `${action.label} (${action.shortcut})`"
                    @click="toggleStatus(action.status)"
                >
                    <component :is="action.icon" />
                    <span class="sr-only">{{ action.label }}</span>
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    tabindex="-1"
                    class="size-7 text-muted-foreground [&_svg]:size-3.5"
                    :disabled="!canPost"
                    :title="
                        finding.is_posted
                            ? 'Already posted to the pull request'
                            : canPostFindings
                              ? 'Post to pull request (p)'
                              : 'Posting is off — enable in Settings → PR review'
                    "
                    @click="post"
                >
                    <Send />
                    <span class="sr-only">Post to pull request</span>
                </Button>
            </div>
        </div>

        <div v-if="expanded" class="cursor-auto space-y-3 pb-3 pl-16 pr-3" @click.stop>
            <MarkdownText :text="finding.body" />

            <div v-if="finding.suggestion" class="space-y-1">
                <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Suggested fix</p>
                <pre class="overflow-x-auto whitespace-pre-wrap rounded-md border bg-muted/50 p-3 font-mono text-xs leading-relaxed">{{
                    finding.suggestion
                }}</pre>
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
                <Button
                    v-for="action in statusActions"
                    :key="action.status"
                    size="sm"
                    :variant="finding.status === action.status ? 'secondary' : 'outline'"
                    class="h-7 px-2.5 text-xs [&_svg]:size-3.5"
                    :disabled="busy || finding.status === action.status"
                    @click="setStatus(action.status)"
                >
                    <component :is="action.icon" />
                    {{ action.label }}
                    <kbd class="ml-0.5 hidden rounded border px-1 font-mono text-[10px] text-muted-foreground lg:inline">{{ action.shortcut }}</kbd>
                </Button>
                <Button
                    v-if="finding.status !== 'new'"
                    size="sm"
                    variant="ghost"
                    class="h-7 px-2.5 text-xs [&_svg]:size-3.5"
                    :disabled="busy"
                    @click="setStatus('new')"
                >
                    <RotateCcw />
                    Reset to new
                </Button>

                <div class="ml-auto flex items-center gap-2">
                    <span v-if="finding.is_posted && finding.posted_at" class="text-xs text-muted-foreground"
                        >Posted {{ timeAgo(finding.posted_at) }}</span
                    >
                    <Button
                        v-if="canChangeCode && !finding.is_resolved"
                        size="sm"
                        variant="outline"
                        class="h-7 px-2.5 text-xs [&_svg]:size-3.5"
                        title="Let Claude fix this (f)"
                        @click="fix"
                    >
                        <Wand2 />
                        Fix with Claude
                    </Button>
                    <Button v-if="canPost" size="sm" class="h-7 px-2.5 text-xs [&_svg]:size-3.5" @click="post">
                        <Send />
                        Post to PR…
                    </Button>
                </div>
            </div>

            <SettingsHint v-if="!canPostFindings && !finding.is_posted" message="Posting is off" />
        </div>
    </div>
</template>
