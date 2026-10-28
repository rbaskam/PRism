<script setup lang="ts">
import ExternalLink from '@/components/ExternalLink.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import RepositoryPicker, { MAX_TRACKED_REPOSITORIES, toRepositorySelection } from '@/components/RepositoryPicker.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { getInitials } from '@/composables/useInitials';
import { useUnsavedChangesGuard } from '@/composables/useUnsavedChangesGuard';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { postJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem, Repository, SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    CircleDashed,
    FolderOpen,
    FolderPlus,
    Info,
    Link2,
    Loader2,
    Lock,
    Pencil,
    ScanSearch,
    Users,
} from 'lucide-vue-next';
import { computed, nextTick, ref, type Component } from 'vue';

interface GithubTeam {
    organization: string;
    slug: string;
    name: string;
}

const props = defineProps<{
    account: {
        login: string;
        name: string | null;
        avatar_url: string | null;
        /** Human readable, e.g. "GitHub CLI login". */
        token_source: string;
        scopes: string[];
        teams: GithubTeam[];
    };
    repositories: Repository[];
    githubCli: {
        binary: string | null;
        path_override: string | null;
    };
    /** The folder scanned last for local checkouts, e.g. ~/Sites. */
    localCodeFolder?: string | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'GitHub settings', href: route('settings.github') }];

const scopesReported = computed(() => props.account.scopes.length > 0);
const missingReadOrg = computed(() => scopesReported.value && !props.account.scopes.includes('read:org'));
const missingNotifications = computed(
    () => scopesReported.value && !props.account.scopes.includes('repo') && !props.account.scopes.includes('notifications'),
);

const confirmingDisconnect = ref(false);
const disconnecting = ref(false);

function disconnect(): void {
    router.delete(route('github.connection.destroy'), {
        onStart: () => (disconnecting.value = true),
        onFinish: () => (disconnecting.value = false),
    });
}

/*
 * Tracked repositories.
 */
const pickerOpen = ref(false);
const trackedForm = useForm({
    repositories: props.repositories.map(toRepositorySelection),
    redirect_to: 'settings.github',
});

/** Full names to preselect in the picker, e.g. untracked checkouts found by a scan. */
const pickerPreselect = ref<string[]>([]);
const rescanAfterTracking = ref(false);

function openPicker(): void {
    trackedForm.repositories = props.repositories.map(toRepositorySelection);
    trackedForm.clearErrors();
    pickerPreselect.value = [];
    rescanAfterTracking.value = false;
    pickerOpen.value = true;
}

/** preserveState keeps the scan results on screen, so newly tracked checkouts can be linked straight away. */
function saveTrackedRepositories(): void {
    trackedForm.put(route('tracked-repositories.update'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            pickerOpen.value = false;

            if (rescanAfterTracking.value) {
                rescanAfterTracking.value = false;
                scanCodeFolder();
            }
        },
    });
}

/*
 * Local code folder: find checkouts of tracked repositories on this computer.
 */
interface LocalCheckout {
    full_name: string;
    path: string;
}

interface LocalCheckoutScan {
    linked: LocalCheckout[];
    already_linked: LocalCheckout[];
    untracked: LocalCheckout[];
}

interface ScanGroup {
    key: keyof LocalCheckoutScan;
    title: string;
    description: string;
    icon: Component;
    iconClass: string;
    checkouts: LocalCheckout[];
}

const codeFolder = ref(props.localCodeFolder ?? '');
const choosingCodeFolder = ref(false);
const scanning = ref(false);
const scanError = ref<string | null>(null);
const scanResult = ref<LocalCheckoutScan | null>(null);

const scanGroups = computed<ScanGroup[]>(() => {
    if (!scanResult.value) {
        return [];
    }

    const { linked, already_linked, untracked } = scanResult.value;
    const checkouts = (count: number): string => (count === 1 ? '1 checkout' : `${count} checkouts`);

    const groups: ScanGroup[] = [
        {
            key: 'linked',
            title: `Linked ${checkouts(linked.length)}`,
            description: 'Claude can now read the code of these repositories.',
            icon: CheckCircle2,
            iconClass: 'text-emerald-600 dark:text-emerald-500',
            checkouts: linked,
        },
        {
            key: 'already_linked',
            title: `${checkouts(already_linked.length)} already linked`,
            description: 'These repositories already had a local checkout folder, so they were left as they are.',
            icon: Link2,
            iconClass: 'text-muted-foreground',
            checkouts: already_linked,
        },
        {
            key: 'untracked',
            title: `${checkouts(untracked.length)} of repositories you don't track`,
            description: 'Track them to see their pull requests here, then they are linked automatically.',
            icon: CircleDashed,
            iconClass: 'text-amber-600 dark:text-amber-500',
            checkouts: untracked,
        },
    ];

    return groups.filter((group) => group.checkouts.length > 0);
});

/** Opens the native "choose folder" dialog (desktop app only). */
async function browseForCodeFolder(): Promise<void> {
    choosingCodeFolder.value = true;
    scanError.value = null;

    try {
        const { path } = await postJson<{ path: string | null }>(
            route('local-folders.store'),
            { title: 'Choose the folder where you keep your clones' },
            'Could not open the folder picker',
        );

        if (path) {
            codeFolder.value = path;
        }
    } catch (error) {
        scanError.value = error instanceof Error ? error.message : 'Could not open the folder picker.';
    } finally {
        choosingCodeFolder.value = false;
    }
}

async function scanCodeFolder(): Promise<void> {
    const folder = codeFolder.value.trim();

    if (folder === '' || scanning.value) {
        return;
    }

    scanning.value = true;
    scanError.value = null;

    try {
        scanResult.value = await postJson<LocalCheckoutScan>(route('local-checkouts.scan'), { folder }, "Couldn't scan that folder");
        router.reload({ only: ['repositories'] });
    } catch (error) {
        scanError.value = error instanceof Error ? error.message : "Couldn't scan that folder.";
    } finally {
        scanning.value = false;
    }
}

function trackUntrackedCheckouts(): void {
    openPicker();
    pickerPreselect.value = scanResult.value?.untracked.map((checkout) => checkout.full_name) ?? [];
    rescanAfterTracking.value = true;
}

const editingRepositoryId = ref<number | null>(null);
const localPathForm = useForm({ local_path: '' });

function editLocalPath(repository: Repository): void {
    editingRepositoryId.value = repository.id;
    localPathForm.local_path = repository.local_path ?? '';
    localPathForm.clearErrors();

    nextTick(() => document.getElementById(`local-path-${repository.id}`)?.focus());
}

const page = usePage<SharedData>();
const browsingForFolder = ref(false);

/** Opens the native "choose folder" dialog (desktop app only). */
async function browseForLocalPath(): Promise<void> {
    browsingForFolder.value = true;

    try {
        const { path } = await postJson<{ path: string | null }>(route('local-folders.store'), {}, 'Could not open the folder picker');

        if (path) {
            localPathForm.local_path = path;
        }
    } catch (error) {
        localPathForm.setError('local_path', error instanceof Error ? error.message : 'Could not open the folder picker.');
    } finally {
        browsingForFolder.value = false;
    }
}

function cancelLocalPath(): void {
    editingRepositoryId.value = null;
    localPathForm.clearErrors();
}

function saveLocalPath(repository: Repository): void {
    localPathForm
        .transform((data) => ({ local_path: data.local_path.trim() || null }))
        .patch(route('repositories.update', { repository: repository.id }), {
            preserveScroll: true,
            onSuccess: () => (editingRepositoryId.value = null),
        });
}

const untrackingId = ref<number | null>(null);

function untrack(repository: Repository): void {
    router.patch(
        route('repositories.update', { repository: repository.id }),
        { is_tracked: false },
        {
            preserveScroll: true,
            onStart: () => (untrackingId.value = repository.id),
            onFinish: () => (untrackingId.value = null),
        },
    );
}

/*
 * GitHub CLI.
 */
const cliForm = useForm({ github_cli_path: props.githubCli.path_override ?? '' });

useUnsavedChangesGuard(() => cliForm.isDirty);

function saveCliPath(): void {
    cliForm.put(route('settings.github.update'), {
        preserveScroll: true,
        onSuccess: () => cliForm.defaults(),
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="GitHub settings" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    title="Connected account"
                    :description="`The GitHub account ${$page.props.name} uses to read pull requests and notifications.`"
                />

                <div class="rounded-lg border">
                    <div class="flex flex-col gap-4 p-4 sm:flex-row sm:items-center">
                        <div class="flex min-w-0 flex-1 items-center gap-3">
                            <Avatar class="size-11">
                                <AvatarImage v-if="account.avatar_url" :src="account.avatar_url" :alt="account.login" />
                                <AvatarFallback>{{ getInitials(account.name ?? account.login) }}</AvatarFallback>
                            </Avatar>
                            <div class="min-w-0">
                                <p class="truncate font-medium">{{ account.name ?? account.login }}</p>
                                <p class="truncate text-sm text-muted-foreground">@{{ account.login }} · {{ account.token_source }}</p>
                            </div>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <Button variant="outline" size="sm" as-child>
                                <Link :href="route('onboarding.github')">Reconnect</Link>
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                class="text-red-600 hover:bg-red-50 hover:text-red-700 dark:text-red-500 dark:hover:bg-red-500/10"
                                @click="confirmingDisconnect = true"
                            >
                                Disconnect
                            </Button>
                        </div>
                    </div>

                    <div class="space-y-3 border-t p-4">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="mr-1 text-sm text-muted-foreground">Token scopes</span>
                            <Badge v-for="scope in account.scopes" :key="scope" variant="secondary" class="font-mono">{{ scope }}</Badge>
                            <span v-if="!scopesReported" class="text-sm text-muted-foreground">none reported</span>
                        </div>

                        <p v-if="!scopesReported" class="flex items-start gap-2 text-sm text-muted-foreground">
                            <Info class="mt-0.5 size-4 shrink-0" />
                            GitHub didn't report any scopes for this token. If it's a fine-grained token, notifications and team review requests may
                            not work. A classic token with repo, read:org and notifications is recommended.
                        </p>

                        <div
                            v-if="missingReadOrg"
                            class="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
                        >
                            <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                            <p>
                                <span class="font-medium">Team review requests can't be detected.</span> The token is missing the
                                <code class="font-mono text-xs">read:org</code> scope, so review requests sent to your teams won't show up as yours.
                                Reconnect with a token that includes it.
                            </p>
                        </div>

                        <div
                            v-if="missingNotifications"
                            class="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
                        >
                            <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                            <p>
                                <span class="font-medium">Notifications can't be read.</span> The token needs the
                                <code class="font-mono text-xs">notifications</code> (or <code class="font-mono text-xs">repo</code>) scope for the
                                inbox and mention alerts. Reconnect with a token that includes it.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-y-6">
                <HeadingSmall
                    title="Your teams"
                    description="Review requests sent to these teams count as requests for you, so they show up as awaiting your review."
                />

                <ul v-if="account.teams.length > 0" class="divide-y rounded-lg border">
                    <li v-for="team in account.teams" :key="`${team.organization}/${team.slug}`" class="flex items-center gap-3 px-4 py-2.5">
                        <Users class="size-4 shrink-0 text-muted-foreground" />
                        <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ team.name }}</span>
                        <span class="truncate font-mono text-xs text-muted-foreground">@{{ team.organization }}/{{ team.slug }}</span>
                    </li>
                </ul>
                <p v-else class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                    No teams found. If you're in teams on GitHub, make sure the token has the
                    <code class="font-mono text-xs">read:org</code> scope. Teams refresh each time GitHub syncs.
                </p>
            </div>

            <div class="space-y-6">
                <HeadingSmall title="Local code folder" description="Where you keep your clones of these repositories." />

                <p class="text-sm text-muted-foreground">
                    Point {{ page.props.name }} at the folder where you keep your clones (for example
                    <code class="rounded bg-muted px-1 py-0.5 font-mono text-xs">~/Sites</code>). It finds checkouts of your tracked repositories, so
                    Claude can read the code at the pull request's exact commit and, if you allow it, make fixes in a separate worktree. Your own
                    checkouts and branches are never changed.
                </p>

                <form class="grid gap-2" @submit.prevent="scanCodeFolder">
                    <Label for="local-code-folder">Folder</Label>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <Input
                            id="local-code-folder"
                            v-model="codeFolder"
                            class="font-mono"
                            spellcheck="false"
                            autocomplete="off"
                            placeholder="/Users/you/Sites"
                        />
                        <div class="flex shrink-0 gap-2">
                            <Button
                                v-if="page.props.isNative"
                                type="button"
                                variant="outline"
                                :disabled="choosingCodeFolder || scanning"
                                @click="browseForCodeFolder"
                            >
                                <Loader2 v-if="choosingCodeFolder" class="animate-spin" />
                                <FolderOpen v-else />
                                Browse…
                            </Button>
                            <Button type="submit" :disabled="scanning || codeFolder.trim() === ''">
                                <Loader2 v-if="scanning" class="animate-spin" />
                                <ScanSearch v-else />
                                {{ scanning ? 'Scanning…' : 'Scan' }}
                            </Button>
                        </div>
                    </div>
                    <p class="text-xs text-muted-foreground">Looks up to three folders deep for git checkouts. Nothing is moved or changed.</p>
                    <InputError :message="scanError ?? undefined" />
                </form>

                <div v-if="scanResult" class="space-y-4 rounded-lg border p-4">
                    <p v-if="scanGroups.length === 0" class="text-sm text-muted-foreground">
                        No git checkouts of GitHub repositories were found in that folder.
                    </p>

                    <div v-for="group in scanGroups" :key="group.key" class="space-y-2">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="flex items-start gap-2">
                                <component :is="group.icon" :class="cn('mt-0.5 size-4 shrink-0', group.iconClass)" />
                                <div>
                                    <p class="text-sm font-medium">{{ group.title }}</p>
                                    <p class="text-xs text-muted-foreground">{{ group.description }}</p>
                                </div>
                            </div>
                            <Button v-if="group.key === 'untracked'" type="button" variant="outline" size="sm" @click="trackUntrackedCheckouts">
                                <FolderPlus />
                                Track these
                            </Button>
                        </div>
                        <ul class="ml-6 max-h-40 divide-y overflow-y-auto rounded-md border">
                            <li
                                v-for="checkout in group.checkouts"
                                :key="`${group.key}-${checkout.path}`"
                                class="flex flex-col gap-0.5 px-3 py-1.5 sm:flex-row sm:items-center sm:gap-3"
                            >
                                <span class="shrink-0 text-sm">{{ checkout.full_name }}</span>
                                <span class="min-w-0 truncate font-mono text-xs text-muted-foreground" :title="checkout.path">{{
                                    checkout.path
                                }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="space-y-6">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <HeadingSmall
                        title="Tracked repositories"
                        description="Pull requests from these repositories appear in the app. Set a local checkout folder so Claude can read the code for better reviews."
                    />
                    <Button variant="outline" size="sm" class="shrink-0" @click="openPicker">
                        <FolderPlus />
                        Add or remove repositories
                    </Button>
                </div>

                <ul v-if="repositories.length > 0" class="divide-y rounded-lg border">
                    <li v-for="repository in repositories" :key="repository.id" class="space-y-2 p-4">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                            <div class="flex min-w-0 flex-1 items-center gap-2">
                                <Link
                                    :href="route('repositories.show', { repository: repository.id })"
                                    class="min-w-0 truncate text-sm hover:underline"
                                >
                                    <span class="text-muted-foreground">{{ repository.owner }}/</span>
                                    <span class="font-medium">{{ repository.name }}</span>
                                </Link>
                                <Lock v-if="repository.is_private" class="size-3.5 shrink-0 text-muted-foreground" aria-label="Private" />
                            </div>
                            <div v-if="repository.counts" class="flex items-center gap-1.5">
                                <Badge variant="muted">{{ repository.counts.open }} open</Badge>
                                <Badge :variant="repository.counts.awaiting ? 'danger' : 'muted'">
                                    {{ repository.counts.awaiting }} awaiting you
                                </Badge>
                            </div>
                            <Button
                                variant="ghost"
                                size="sm"
                                class="h-7 px-2 text-muted-foreground"
                                :disabled="untrackingId === repository.id"
                                @click="untrack(repository)"
                            >
                                <Loader2 v-if="untrackingId === repository.id" class="animate-spin" />
                                Untrack
                            </Button>
                        </div>

                        <form v-if="editingRepositoryId === repository.id" class="space-y-2" @submit.prevent="saveLocalPath(repository)">
                            <Label :for="`local-path-${repository.id}`" class="text-xs text-muted-foreground">Local checkout folder</Label>
                            <div class="flex flex-col gap-2 sm:flex-row">
                                <Input
                                    :id="`local-path-${repository.id}`"
                                    v-model="localPathForm.local_path"
                                    class="h-9 font-mono text-xs"
                                    spellcheck="false"
                                    autocomplete="off"
                                    :placeholder="`/Users/you/code/${repository.name}`"
                                    @keydown.esc="cancelLocalPath"
                                />
                                <div class="flex shrink-0 gap-2">
                                    <Button
                                        v-if="page.props.isNative"
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        class="h-9"
                                        :disabled="browsingForFolder"
                                        @click="browseForLocalPath"
                                    >
                                        <Loader2 v-if="browsingForFolder" class="animate-spin" />
                                        <FolderOpen v-else />
                                        Browse…
                                    </Button>
                                    <Button type="submit" size="sm" class="h-9" :disabled="localPathForm.processing">
                                        <Loader2 v-if="localPathForm.processing" class="animate-spin" />
                                        Save
                                    </Button>
                                    <Button type="button" variant="ghost" size="sm" class="h-9" @click="cancelLocalPath">Cancel</Button>
                                </div>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                The absolute path of a clone of this repository on this computer. Leave blank to remove it.
                            </p>
                            <InputError :message="localPathForm.errors.local_path" />
                        </form>

                        <button
                            v-else
                            type="button"
                            class="group flex max-w-full items-center gap-2 rounded-md text-left text-xs text-muted-foreground hover:text-foreground"
                            @click="editLocalPath(repository)"
                        >
                            <FolderOpen class="size-3.5 shrink-0" />
                            <span v-if="repository.local_path" class="truncate font-mono">{{ repository.local_path }}</span>
                            <span v-else>No local checkout folder. Set one so Claude can read the code.</span>
                            <Pencil class="size-3 shrink-0 opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100" />
                        </button>
                    </li>
                </ul>

                <div v-else class="flex flex-col items-center gap-3 rounded-lg border border-dashed p-8 text-center">
                    <p class="text-sm text-muted-foreground">You're not tracking any repositories yet.</p>
                    <Button size="sm" @click="openPicker">
                        <FolderPlus />
                        Choose repositories
                    </Button>
                </div>
            </div>

            <div class="space-y-6">
                <HeadingSmall
                    title="GitHub CLI (gh)"
                    description="Claude uses gh to fetch pull requests while it reviews. It's found automatically in most setups."
                />

                <form class="space-y-4" @submit.prevent="saveCliPath">
                    <p class="text-sm">
                        <span class="text-muted-foreground">Detected:</span>
                        <code v-if="githubCli.binary" class="ml-1 break-all font-mono text-xs">{{ githubCli.binary }}</code>
                        <span v-else class="ml-1 text-amber-700 dark:text-amber-400">not found</span>
                    </p>
                    <p v-if="!githubCli.binary" class="text-sm text-muted-foreground">
                        <ExternalLink href="https://cli.github.com" class="font-medium text-primary underline-offset-4 hover:underline">
                            Install the GitHub CLI
                        </ExternalLink>
                        or set its path below.
                    </p>

                    <div class="grid gap-2">
                        <Label for="github-cli-path">Path override</Label>
                        <Input
                            id="github-cli-path"
                            v-model="cliForm.github_cli_path"
                            class="font-mono"
                            spellcheck="false"
                            autocomplete="off"
                            placeholder="/opt/homebrew/bin/gh"
                        />
                        <p class="text-xs text-muted-foreground">
                            Leave blank to detect it automatically. Run <code class="rounded bg-muted px-1 py-0.5 font-mono">which gh</code> in a
                            terminal to find it.
                        </p>
                        <InputError :message="cliForm.errors.github_cli_path" />
                    </div>

                    <div class="flex items-center gap-3">
                        <Button type="submit" :disabled="cliForm.processing || !cliForm.isDirty">
                            <Loader2 v-if="cliForm.processing" class="animate-spin" />
                            Save
                        </Button>
                        <Transition
                            enter-active-class="transition ease-in-out"
                            enter-from-class="opacity-0"
                            leave-active-class="transition ease-in-out"
                            leave-to-class="opacity-0"
                        >
                            <p v-show="cliForm.recentlySuccessful" class="text-sm text-muted-foreground">Saved.</p>
                        </Transition>
                    </div>
                </form>
            </div>

            <Dialog v-model:open="confirmingDisconnect">
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Disconnect GitHub?</DialogTitle>
                        <DialogDescription>
                            {{ $page.props.name }} stops syncing pull requests and notifications for @{{ account.login }} and takes you back to the
                            setup screen. Your tracked repositories and review history stay on this computer.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button variant="outline">Cancel</Button>
                        </DialogClose>
                        <Button variant="destructive" :disabled="disconnecting" @click="disconnect">
                            <Loader2 v-if="disconnecting" class="animate-spin" />
                            Disconnect
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog v-model:open="pickerOpen">
                <DialogContent class="flex max-h-[90vh] max-w-3xl flex-col overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Tracked repositories</DialogTitle>
                        <DialogDescription>Choose the repositories whose pull requests you want to follow.</DialogDescription>
                    </DialogHeader>
                    <form id="tracked-repositories-form" class="min-h-0 flex-1" @submit.prevent="saveTrackedRepositories">
                        <RepositoryPicker
                            v-model="trackedForm.repositories"
                            :preselect="pickerPreselect"
                            list-class="max-h-[min(26rem,calc(90vh-20rem))]"
                        />
                        <InputError :message="trackedForm.errors.repositories" class="mt-2" />
                    </form>
                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button variant="outline">Cancel</Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            form="tracked-repositories-form"
                            :disabled="trackedForm.processing || trackedForm.repositories.length > MAX_TRACKED_REPOSITORIES"
                        >
                            <Loader2 v-if="trackedForm.processing" class="animate-spin" />
                            Save ({{ trackedForm.repositories.length }})
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </SettingsLayout>
    </AppLayout>
</template>
