<script setup lang="ts">
import ExternalLink from '@/components/ExternalLink.vue';
import InputError from '@/components/InputError.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useExternalLink } from '@/composables/useExternalLink';
import { useInitials } from '@/composables/useInitials';
import OnboardingLayout from '@/layouts/OnboardingLayout.vue';
import { postJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import type { GithubUser } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    Check,
    ChevronDown,
    Copy,
    ExternalLink as ExternalLinkIcon,
    Github,
    KeyRound,
    Loader2,
    Terminal,
    XCircle,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref } from 'vue';

const props = defineProps<{
    account: GithubUser | null;
    /** Settings → GitHub sends the user here to reconnect; connecting then goes back to Settings instead of on to step 2. */
    onboardingCompleted: boolean;
    githubCliInstalled: boolean;
    deviceFlowAvailable: boolean;
    requiredScopes: string[];
}>();

interface DeviceCode {
    device_code: string;
    user_code: string;
    verification_uri: string;
    interval: number;
    expires_in: number;
}

interface DevicePollResult {
    status: 'pending' | 'slow_down' | 'expired' | 'denied' | 'authorized' | 'error';
    message?: string;
    login?: string;
    redirect?: string;
}

type DeviceFlowState = 'idle' | 'starting' | 'waiting' | 'authorized' | 'failed';

const { openExternal } = useExternalLink();
const { getInitials } = useInitials();

/** Poll failures in a row (e.g. a network blip) tolerated before giving up. */
const MAX_POLL_FAILURES = 3;

const isReconnecting = computed(() => props.onboardingCompleted && props.account !== null);
const pageTitle = computed(() => (isReconnecting.value ? 'Reconnect GitHub' : 'Connect GitHub'));
const nextRoute = computed(() => (props.onboardingCompleted ? route('settings.github') : route('onboarding.repositories')));

/** Reconnecting goes straight to the ways to sign in: the user already chose to connect again. */
const choosingAccount = ref(props.account === null || props.onboardingCompleted);
const tokenOpen = ref(!props.deviceFlowAvailable && !props.githubCliInstalled);

const tokenUrl = computed(() => `https://github.com/settings/tokens/new?scopes=${props.requiredScopes.join(',')}&description=PRism`);

const cliForm = useForm({});
const tokenForm = useForm({ token: '' });

/** The CLI route reports problems under the "gh" key, which the empty form doesn't know about. */
const cliError = computed(() => (cliForm.errors as Partial<Record<string, string>>).gh);

const deviceState = ref<DeviceFlowState>('idle');
const deviceCode = ref<DeviceCode | null>(null);
const deviceError = ref<string | null>(null);
const codeCopied = ref(false);

let pollTimer: ReturnType<typeof setTimeout> | undefined;
let pollIntervalSeconds = 5;
let expiresAt = 0;
let pollFailures = 0;

function connectWithCli(): void {
    cliForm.post(route('github.connection.cli'));
}

function connectWithToken(): void {
    tokenForm.post(route('github.connection.token'), {
        onError: () => tokenForm.reset('token'),
    });
}

function stopPolling(): void {
    clearTimeout(pollTimer);
    pollTimer = undefined;
}

function fail(message: string): void {
    stopPolling();
    deviceError.value = message;
    deviceState.value = 'failed';
}

async function copyCode(): Promise<void> {
    if (!deviceCode.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(deviceCode.value.user_code);
        codeCopied.value = true;
    } catch {
        codeCopied.value = false;
    }
}

function openVerificationPage(): void {
    openExternal(deviceCode.value?.verification_uri);
}

async function startDeviceFlow(): Promise<void> {
    stopPolling();
    deviceError.value = null;
    codeCopied.value = false;
    deviceState.value = 'starting';

    try {
        const code = await postJson<DeviceCode>(route('github.device.store'), {}, 'GitHub sign in failed');

        deviceCode.value = code;
        pollIntervalSeconds = Math.max(code.interval, 1);
        expiresAt = Date.now() + code.expires_in * 1000;
        pollFailures = 0;
        deviceState.value = 'waiting';

        await copyCode();
        openVerificationPage();
        schedulePoll();
    } catch (error) {
        fail(error instanceof Error ? error.message : 'GitHub sign in could not be started.');
    }
}

function schedulePoll(): void {
    stopPolling();
    pollTimer = setTimeout(poll, pollIntervalSeconds * 1000);
}

async function poll(): Promise<void> {
    if (deviceState.value !== 'waiting' || !deviceCode.value) {
        return;
    }

    if (Date.now() > expiresAt) {
        fail('The code expired before it was entered. Start again to get a new one.');

        return;
    }

    let result: DevicePollResult;

    try {
        result = await postJson<DevicePollResult>(
            route('github.device.poll'),
            { device_code: deviceCode.value.device_code },
            'GitHub sign in failed',
        );
        pollFailures = 0;
    } catch (error) {
        if (++pollFailures >= MAX_POLL_FAILURES) {
            fail(error instanceof Error ? error.message : 'Lost contact with GitHub.');
        } else {
            schedulePoll();
        }

        return;
    }

    // The user may have cancelled while the request was in flight.
    if (deviceState.value !== 'waiting') {
        return;
    }

    switch (result.status) {
        case 'authorized':
            deviceState.value = 'authorized';
            router.visit(result.redirect ?? nextRoute.value);

            return;
        case 'expired':
            fail('The code expired before it was entered. Start again to get a new one.');

            return;
        case 'denied':
            fail('The sign in was cancelled on GitHub.');

            return;
        case 'error':
            fail(result.message ?? 'GitHub sign in failed.');

            return;
        case 'slow_down':
            pollIntervalSeconds += 5;
            break;
    }

    schedulePoll();
}

function cancelDeviceFlow(): void {
    stopPolling();
    deviceCode.value = null;
    deviceState.value = 'idle';
}

onBeforeUnmount(stopPolling);
</script>

<template>
    <OnboardingLayout
        :step="onboardingCompleted ? undefined : 1"
        :title="pageTitle"
        :description="
            isReconnecting
                ? `Sign in again to give ${$page.props.name} a new token, or connect a different GitHub account. Your token is stored encrypted on this computer.`
                : `${$page.props.name} reads your pull requests, review requests and notifications from GitHub. Your token is stored encrypted on this computer.`
        "
        :back="isReconnecting ? { href: route('settings.github'), label: 'Back to Settings' } : undefined"
    >
        <Head :title="pageTitle" />

        <div v-if="account && !choosingAccount" class="space-y-6">
            <div class="flex items-center gap-4 rounded-lg border p-4">
                <Avatar class="size-12">
                    <AvatarImage v-if="account.avatar_url" :src="account.avatar_url" :alt="account.login" />
                    <AvatarFallback>{{ getInitials(account.name ?? account.login) }}</AvatarFallback>
                </Avatar>
                <div class="min-w-0 flex-1">
                    <p class="truncate font-medium">{{ account.name ?? account.login }}</p>
                    <p class="truncate text-sm text-muted-foreground">@{{ account.login }}</p>
                </div>
                <Badge variant="success"><Check />Connected</Badge>
            </div>

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between">
                <Button variant="ghost" @click="choosingAccount = true">Use a different account</Button>
                <Button as-child>
                    <Link :href="route('onboarding.repositories')">
                        Continue
                        <ArrowRight />
                    </Link>
                </Button>
            </div>
        </div>

        <div v-else class="space-y-4">
            <div v-if="isReconnecting && account" class="flex items-center gap-3 rounded-lg border bg-muted/40 p-4">
                <Avatar class="size-9">
                    <AvatarImage v-if="account.avatar_url" :src="account.avatar_url" :alt="account.login" />
                    <AvatarFallback>{{ getInitials(account.name ?? account.login) }}</AvatarFallback>
                </Avatar>
                <div class="min-w-0 flex-1 text-sm">
                    <p class="truncate">
                        Connected as <span class="font-medium">@{{ account.login }}</span>
                    </p>
                    <p class="text-muted-foreground">
                        Connecting a different account clears the notifications and review requests of @{{ account.login }}. Tracked repositories and
                        AI reviews stay.
                    </p>
                </div>
            </div>

            <section v-if="deviceFlowAvailable" class="rounded-lg border p-5">
                <div class="flex items-start gap-3">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-md bg-muted">
                        <Github class="size-5" />
                    </div>
                    <div class="min-w-0 flex-1 space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="font-medium">Sign in with GitHub</h2>
                            <Badge variant="success">Recommended</Badge>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            Approve {{ $page.props.name }} in your browser. There's no token to create or copy.
                        </p>
                    </div>
                </div>

                <div class="mt-4">
                    <Button v-if="deviceState === 'idle'" @click="startDeviceFlow">
                        <Github />
                        Sign in with GitHub
                    </Button>

                    <p v-else-if="deviceState === 'starting'" class="flex items-center gap-2 text-sm text-muted-foreground">
                        <Loader2 class="size-4 animate-spin" />
                        Asking GitHub for a sign-in code…
                    </p>

                    <div v-else-if="deviceState === 'waiting' && deviceCode" class="space-y-4">
                        <div class="rounded-lg border border-dashed bg-muted/40 p-4 text-center">
                            <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Enter this code on GitHub</p>
                            <div class="mt-2 flex items-center justify-center gap-2">
                                <span class="select-all font-mono text-3xl font-semibold tracking-[0.2em]">{{ deviceCode.user_code }}</span>
                                <Button variant="ghost" size="icon" :title="codeCopied ? 'Copied' : 'Copy code'" @click="copyCode">
                                    <Check v-if="codeCopied" class="text-emerald-600" />
                                    <Copy v-else />
                                    <span class="sr-only">Copy code</span>
                                </Button>
                            </div>
                            <p class="mt-2 text-xs text-muted-foreground">
                                <template v-if="codeCopied">The code is on your clipboard. </template>
                                We opened <span class="font-mono">{{ deviceCode.verification_uri.replace(/^https?:\/\//, '') }}</span> in your
                                browser.
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <Button variant="outline" size="sm" @click="openVerificationPage">
                                <ExternalLinkIcon />
                                Open GitHub again
                            </Button>
                            <Button variant="ghost" size="sm" @click="cancelDeviceFlow">Cancel</Button>
                            <span class="ml-auto flex items-center gap-2 text-sm text-muted-foreground">
                                <Loader2 class="size-4 animate-spin" />
                                Waiting for you to approve…
                            </span>
                        </div>
                    </div>

                    <p
                        v-else-if="deviceState === 'authorized'"
                        class="flex items-center gap-2 text-sm font-medium text-emerald-700 dark:text-emerald-400"
                    >
                        <Check class="size-4" />
                        {{ onboardingCompleted ? 'Connected. Going back to Settings…' : 'Connected. Loading your repositories…' }}
                    </p>

                    <div v-else-if="deviceState === 'failed'" class="space-y-3">
                        <p class="flex items-start gap-2 text-sm text-red-600 dark:text-red-500">
                            <XCircle class="mt-0.5 size-4 shrink-0" />
                            {{ deviceError }}
                        </p>
                        <Button variant="outline" @click="startDeviceFlow">Try again</Button>
                    </div>
                </div>
            </section>

            <section v-if="githubCliInstalled" class="rounded-lg border p-5">
                <div class="flex items-start gap-3">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-md bg-muted">
                        <Terminal class="size-5" />
                    </div>
                    <div class="min-w-0 flex-1 space-y-1">
                        <h2 class="font-medium">Use my GitHub CLI login (gh)</h2>
                        <p class="text-sm text-muted-foreground">
                            Reuse the account you signed into with
                            <code class="rounded bg-muted px-1 py-0.5 font-mono text-xs">gh auth login</code> on this computer.
                        </p>
                    </div>
                </div>
                <div class="mt-4 space-y-2">
                    <Button :variant="deviceFlowAvailable ? 'outline' : 'default'" :disabled="cliForm.processing" @click="connectWithCli">
                        <Loader2 v-if="cliForm.processing" class="animate-spin" />
                        <Terminal v-else />
                        Use my gh login
                    </Button>
                    <InputError :message="cliError" />
                </div>
            </section>

            <Collapsible v-model:open="tokenOpen" class="rounded-lg border">
                <CollapsibleTrigger class="flex w-full items-start gap-3 p-5 text-left">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-md bg-muted">
                        <KeyRound class="size-5" />
                    </span>
                    <span class="min-w-0 flex-1 space-y-1">
                        <span class="block font-medium">Paste a personal access token</span>
                        <span class="block text-sm text-muted-foreground">Create a classic token on GitHub and paste it here.</span>
                    </span>
                    <ChevronDown :class="cn('mt-1 size-4 shrink-0 text-muted-foreground transition-transform', tokenOpen && 'rotate-180')" />
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <form class="space-y-4 px-5 pb-5" @submit.prevent="connectWithToken">
                        <ol class="list-decimal space-y-3 pl-5 text-sm">
                            <li class="space-y-2">
                                <p>Create a <span class="font-medium">classic</span> token with these scopes:</p>
                                <div class="flex flex-wrap gap-1.5">
                                    <Badge v-for="scope in requiredScopes" :key="scope" variant="secondary" class="font-mono">{{ scope }}</Badge>
                                </div>
                                <p class="text-muted-foreground">
                                    <span class="font-mono text-xs">read:org</span> lets {{ $page.props.name }} see which teams you're in, so review
                                    requests sent to a team (like <span class="font-mono text-xs">@your-org/reviewers</span>) show up as yours.
                                </p>
                                <ExternalLink
                                    :href="tokenUrl"
                                    class="inline-flex items-center gap-1 font-medium text-primary underline-offset-4 hover:underline"
                                >
                                    Create a token on GitHub
                                    <ExternalLinkIcon class="size-3.5" />
                                </ExternalLink>
                            </li>
                            <li class="space-y-2">
                                <Label for="github-token">Paste the token</Label>
                                <div class="flex flex-col gap-2 sm:flex-row">
                                    <Input
                                        id="github-token"
                                        v-model="tokenForm.token"
                                        type="password"
                                        autocomplete="off"
                                        spellcheck="false"
                                        placeholder="ghp_…"
                                        class="font-mono"
                                    />
                                    <Button type="submit" :disabled="tokenForm.processing || tokenForm.token === ''" class="shrink-0">
                                        <Loader2 v-if="tokenForm.processing" class="animate-spin" />
                                        Connect
                                    </Button>
                                </div>
                                <InputError :message="tokenForm.errors.token" />
                            </li>
                        </ol>
                    </form>
                </CollapsibleContent>
            </Collapsible>

            <div v-if="account" class="flex justify-end">
                <Button v-if="isReconnecting" variant="ghost" as-child>
                    <Link :href="route('settings.github')">Keep using @{{ account.login }}</Link>
                </Button>
                <Button v-else variant="ghost" @click="choosingAccount = false">Keep using @{{ account.login }}</Button>
            </div>
        </div>
    </OnboardingLayout>
</template>
