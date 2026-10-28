<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import ExternalLink from '@/components/ExternalLink.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import MarkdownText from '@/components/pull-request/MarkdownText.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { useExternalLink } from '@/composables/useExternalLink';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { timeAgo } from '@/lib/pull-requests';
import { cn } from '@/lib/utils';
import type { AppRelease, BreadcrumbItem, ChangelogVersion, SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    ArrowUpCircle,
    Bug,
    CheckCircle2,
    ChevronRight,
    CircleHelp,
    Download,
    ExternalLink as ExternalLinkIcon,
    Github,
    Loader2,
    RefreshCw,
    Tag,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    currentVersion: string;
    latestRelease: AppRelease | null;
    updateAvailable: boolean;
    checkedAt: string | null;
    repositoryUrl: string;
    /** Released versions from CHANGELOG.md, newest first. */
    releases: ChangelogVersion[];
    unreleased: string | null;
}>();

const page = usePage<SharedData>();
const { openExternal } = useExternalLink();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'About', href: route('settings.about') }];

/** The newest releases start expanded; older ones are collapsed. */
const EXPANDED_RELEASES = 3;

const checking = ref(false);
const expandedVersions = ref<Set<string>>(new Set(props.releases.slice(0, EXPANDED_RELEASES).map((release) => release.version)));

const hasUnreleasedNotes = computed(() => Boolean(props.unreleased && /^\s*[-*] \S/m.test(props.unreleased)));

const links = computed(() => [
    { label: 'GitHub repository', href: props.repositoryUrl, icon: Github },
    { label: 'Report an issue', href: `${props.repositoryUrl}/issues/new`, icon: Bug },
    { label: 'Releases', href: `${props.repositoryUrl}/releases`, icon: Tag },
]);

function formatDate(date: string | null): string {
    if (!date) {
        return '';
    }

    return new Date(date.length === 10 ? `${date}T00:00:00` : date).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

function isExpanded(version: string): boolean {
    return expandedVersions.value.has(version);
}

function setExpanded(version: string, expanded: boolean): void {
    const versions = new Set(expandedVersions.value);

    if (expanded) {
        versions.add(version);
    } else {
        versions.delete(version);
    }

    expandedVersions.value = versions;
}

function checkForUpdates(): void {
    router.post(
        route('updates.check'),
        {},
        {
            preserveScroll: true,
            onStart: () => (checking.value = true),
            onFinish: () => (checking.value = false),
        },
    );
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="About" />

        <SettingsLayout>
            <div class="space-y-6">
                <div class="flex items-center gap-4">
                    <AppLogoIcon class="size-16 shrink-0" />
                    <div class="min-w-0 space-y-1">
                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <h3 class="text-2xl font-semibold tracking-tight">{{ page.props.name }}</h3>
                            <span class="font-mono text-sm text-muted-foreground">Version {{ currentVersion }}</span>
                        </div>
                        <p class="text-sm text-muted-foreground">Review pull requests across all your GitHub repositories with Claude Code.</p>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Button v-for="link in links" :key="link.label" variant="outline" size="sm" as-child>
                        <ExternalLink :href="link.href">
                            <component :is="link.icon" />
                            {{ link.label }}
                        </ExternalLink>
                    </Button>
                </div>

                <p class="text-xs text-muted-foreground">Free to use and modify. MIT licence with the Commons Clause: it may not be sold.</p>
            </div>

            <div class="space-y-6">
                <HeadingSmall title="Updates" :description="`${page.props.name} checks its GitHub releases for new versions.`" />

                <div
                    v-if="updateAvailable && latestRelease"
                    class="space-y-4 rounded-lg border border-emerald-300 bg-emerald-50/60 p-4 dark:border-emerald-500/30 dark:bg-emerald-500/10"
                >
                    <div class="flex items-start gap-3">
                        <ArrowUpCircle class="mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                        <div class="min-w-0 flex-1 space-y-0.5">
                            <p class="font-medium">Version {{ latestRelease.version }} is available</p>
                            <p class="text-sm text-muted-foreground">
                                <template v-if="latestRelease.name && latestRelease.name.replace(/^v/, '') !== latestRelease.version">
                                    {{ latestRelease.name }} ·
                                </template>
                                You have {{ currentVersion }}
                                <template v-if="latestRelease.published_at"> · released {{ timeAgo(latestRelease.published_at) }}</template>
                            </p>
                        </div>
                    </div>

                    <div v-if="latestRelease.notes.trim()" class="max-h-80 overflow-y-auto rounded-md border bg-background p-4">
                        <MarkdownText :text="latestRelease.notes" />
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <Button @click="openExternal(latestRelease.download_url)">
                            <Download />
                            Download
                        </Button>
                        <Button variant="outline" @click="openExternal(latestRelease.url)">
                            <ExternalLinkIcon />
                            View release
                        </Button>
                    </div>
                    <p class="text-xs text-muted-foreground">Install the new version over this one; your data and settings are kept.</p>
                </div>

                <div v-else-if="!checkedAt" class="flex items-start gap-3 rounded-lg border p-4">
                    <CircleHelp class="mt-0.5 size-5 shrink-0 text-muted-foreground" />
                    <div class="space-y-0.5">
                        <p class="font-medium">Not checked yet</p>
                        <p class="text-sm text-muted-foreground">Click Check for updates to see whether a newer version is out.</p>
                    </div>
                </div>

                <div v-else class="flex items-start gap-3 rounded-lg border p-4">
                    <CheckCircle2 class="mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-500" />
                    <div class="space-y-0.5">
                        <p class="font-medium">You're up to date</p>
                        <p class="text-sm text-muted-foreground">
                            <template v-if="!latestRelease">No releases have been published yet.</template>
                            <template v-else>{{ page.props.name }} {{ currentVersion }} is the latest version.</template>
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <Button variant="outline" :disabled="checking" @click="checkForUpdates">
                        <Loader2 v-if="checking" class="animate-spin" />
                        <RefreshCw v-else />
                        Check for updates
                    </Button>
                    <span class="text-sm text-muted-foreground">
                        {{ checkedAt ? `Last checked ${timeAgo(checkedAt)}` : 'Never checked' }}
                    </span>
                </div>
            </div>

            <div class="space-y-6">
                <HeadingSmall title="Changelog" description="What changed in each version." />

                <div v-if="releases.length > 0 || hasUnreleasedNotes" class="divide-y rounded-lg border">
                    <Collapsible
                        v-if="hasUnreleasedNotes && unreleased"
                        :open="isExpanded('Unreleased')"
                        @update:open="(open: boolean) => setExpanded('Unreleased', open)"
                    >
                        <CollapsibleTrigger class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-muted/40">
                            <ChevronRight
                                :class="cn('size-4 shrink-0 text-muted-foreground transition-transform', isExpanded('Unreleased') && 'rotate-90')"
                            />
                            <span class="font-medium">Coming next</span>
                            <Badge variant="muted">Unreleased</Badge>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <div class="px-4 pb-4 pl-11">
                                <MarkdownText :text="unreleased" />
                            </div>
                        </CollapsibleContent>
                    </Collapsible>

                    <Collapsible
                        v-for="release in releases"
                        :key="release.version"
                        :open="isExpanded(release.version)"
                        @update:open="(open: boolean) => setExpanded(release.version, open)"
                    >
                        <CollapsibleTrigger class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-muted/40">
                            <ChevronRight
                                :class="cn('size-4 shrink-0 text-muted-foreground transition-transform', isExpanded(release.version) && 'rotate-90')"
                            />
                            <span class="font-mono font-medium">v{{ release.version }}</span>
                            <Badge v-if="release.version === currentVersion" variant="success">Installed</Badge>
                            <span v-if="release.date" class="ml-auto text-sm text-muted-foreground">{{ formatDate(release.date) }}</span>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <div class="px-4 pb-4 pl-11">
                                <MarkdownText v-if="release.notes" :text="release.notes" />
                                <p v-else class="text-sm text-muted-foreground">No notes for this version.</p>
                            </div>
                        </CollapsibleContent>
                    </Collapsible>
                </div>

                <p v-else class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">No versions have been released yet.</p>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
