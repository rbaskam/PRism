<script setup lang="ts">
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import {
    attentionFilterOptions,
    type PullRequestFilterState,
    type PullRequestStats,
    type RequestedFilter,
} from '@/composables/usePullRequestFilters';
import { attentionStyles } from '@/lib/pull-requests';
import { cn } from '@/lib/utils';
import type { ReviewAttention } from '@/types';
import { onKeyStroke } from '@vueuse/core';
import { AlarmClock, Archive, CircleX, EyeOff, Search, Sparkles, X } from 'lucide-vue-next';
import { computed, useTemplateRef } from 'vue';

const props = withDefaults(
    defineProps<{
        stats: PullRequestStats;
        /** Teams ("org/slug") the user belongs to. */
        teams?: string[];
        /** When given, a repository select is shown. */
        repositories?: { id: number; full_name: string }[] | null;
        /** Open pull requests the user snoozed; the Snoozed chip only shows when there are some. */
        snoozedCount?: number;
    }>(),
    {
        teams: () => [],
        repositories: null,
        snoozedCount: 0,
    },
);

const filters = defineModel<PullRequestFilterState>({ required: true });

const searchInput = useTemplateRef<InstanceType<typeof Input>>('searchInput');

function update<K extends keyof PullRequestFilterState>(key: K, value: PullRequestFilterState[K]): void {
    filters.value = { ...filters.value, [key]: value };
}

function toggleAttention(attention: ReviewAttention): void {
    const selected = filters.value.attention;

    update('attention', selected.includes(attention) ? selected.filter((value) => value !== attention) : [...selected, attention]);
}

const teamOptions = computed<string[]>(() => {
    const teams = new Set(props.teams);

    if (filters.value.requested.startsWith('team:')) {
        teams.add(filters.value.requested.slice('team:'.length));
    }

    return [...teams].sort((a, b) => a.localeCompare(b));
});

const toggles = [
    { key: 'hideDrafts', label: 'Hide drafts', icon: EyeOff },
    { key: 'onlyWithFindings', label: 'With AI findings', icon: Sparkles },
    { key: 'onlyFailingChecks', label: 'Failing checks', icon: CircleX },
    { key: 'showClosed', label: 'Recently closed', icon: Archive },
] as const;

/** A drop-down that is filtering the list gets the accent border, so it stands out from one left on "All". */
const activeSelectClass = '[&_select]:border-primary [&_select]:ring-1 [&_select]:ring-primary';

function chipClass(isActive: boolean): string {
    return cn(
        'inline-flex h-7 items-center gap-1.5 rounded-full border px-2.5 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring [&_svg]:size-3.5',
        isActive ? 'border-primary bg-primary text-primary-foreground hover:bg-primary/90' : 'bg-background text-foreground hover:bg-accent',
    );
}

function isTypingInField(target: EventTarget | null): boolean {
    return target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));
}

function focusSearch(): void {
    const element = searchInput.value?.$el as HTMLInputElement | undefined;

    element?.focus();
    element?.select();
}

onKeyStroke('/', (event) => {
    if (event.metaKey || event.ctrlKey || event.altKey || isTypingInField(event.target)) {
        return;
    }

    event.preventDefault();
    focusSearch();
});

function onSearchEscape(event: KeyboardEvent): void {
    if (filters.value.search !== '') {
        update('search', '');

        return;
    }

    (event.target as HTMLElement).blur();
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <div class="relative min-w-[14rem] flex-1">
                <Search class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    ref="searchInput"
                    type="search"
                    :model-value="filters.search"
                    placeholder="Search title, number, author, repository or branch…"
                    aria-label="Search pull requests"
                    class="h-9 pl-8 pr-9 [&::-webkit-search-cancel-button]:hidden"
                    @update:model-value="(value) => update('search', String(value))"
                    @keydown.esc="onSearchEscape"
                />
                <button
                    v-if="filters.search"
                    type="button"
                    class="absolute right-2 top-1/2 -translate-y-1/2 rounded p-0.5 text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    title="Clear search"
                    @click="update('search', '')"
                >
                    <X class="size-4" />
                    <span class="sr-only">Clear search</span>
                </button>
                <kbd
                    v-else
                    class="pointer-events-none absolute right-2.5 top-1/2 hidden -translate-y-1/2 rounded border bg-muted px-1.5 font-mono text-[10px] leading-4 text-muted-foreground sm:block"
                    title="Press / to search"
                >
                    /
                </kbd>
            </div>

            <NativeSelect
                v-if="repositories"
                :model-value="filters.repository"
                :class="cn('w-full sm:w-56', filters.repository !== '' && activeSelectClass)"
                aria-label="Filter by repository"
                @update:model-value="(value) => update('repository', String(value ?? ''))"
            >
                <option value="">All repositories</option>
                <option v-for="repository in repositories" :key="repository.id" :value="String(repository.id)">{{ repository.full_name }}</option>
            </NativeSelect>

            <NativeSelect
                :model-value="filters.requested"
                :class="cn('w-full sm:w-56', filters.requested !== 'any' && activeSelectClass)"
                aria-label="Filter by how your review was requested"
                @update:model-value="(value) => update('requested', String(value ?? 'any') as RequestedFilter)"
            >
                <option value="any">Requested: any way</option>
                <option value="direct">Requested from you directly</option>
                <option value="team">Requested via any team</option>
                <optgroup v-if="teamOptions.length > 0" label="Requested via team">
                    <option v-for="team in teamOptions" :key="team" :value="`team:${team}`">via @{{ team }}</option>
                </optgroup>
            </NativeSelect>
        </div>

        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
            <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Filter by status">
                <button
                    v-for="option in attentionFilterOptions"
                    :key="option.value"
                    type="button"
                    :aria-pressed="filters.attention.includes(option.value)"
                    :class="chipClass(filters.attention.includes(option.value))"
                    :title="attentionStyles[option.value].label"
                    @click="toggleAttention(option.value)"
                >
                    <span class="size-2 shrink-0 rounded-full" :class="attentionStyles[option.value].dot" aria-hidden="true" />
                    {{ option.label }}
                    <span
                        class="tabular-nums"
                        :class="filters.attention.includes(option.value) ? 'text-primary-foreground/70' : 'text-muted-foreground'"
                    >
                        {{ stats.by_attention[option.value] }}
                    </span>
                </button>
            </div>

            <span class="hidden h-5 w-px bg-border md:block" aria-hidden="true" />

            <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="More filters">
                <button
                    v-for="toggle in toggles"
                    :key="toggle.key"
                    type="button"
                    :aria-pressed="filters[toggle.key]"
                    :class="chipClass(filters[toggle.key])"
                    @click="update(toggle.key, !filters[toggle.key])"
                >
                    <component :is="toggle.icon" />
                    {{ toggle.label }}
                </button>
                <button
                    v-if="snoozedCount > 0 || filters.showSnoozed"
                    type="button"
                    :aria-pressed="filters.showSnoozed"
                    :class="chipClass(filters.showSnoozed)"
                    title="Also show pull requests you snoozed"
                    @click="update('showSnoozed', !filters.showSnoozed)"
                >
                    <AlarmClock />
                    Snoozed
                    <span class="tabular-nums" :class="filters.showSnoozed ? 'text-primary-foreground/70' : 'text-muted-foreground'">
                        {{ snoozedCount }}
                    </span>
                </button>
            </div>
        </div>
    </div>
</template>
