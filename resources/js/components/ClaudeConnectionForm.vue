<script setup lang="ts">
import ExternalLink from '@/components/ExternalLink.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChangesGuard } from '@/composables/useUnsavedChangesGuard';
import { cn } from '@/lib/utils';
import type { ClaudeStatus } from '@/types';
import { router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    ChevronDown,
    ExternalLink as ExternalLinkIcon,
    KeyRound,
    Loader2,
    RefreshCw,
    Terminal,
    Ticket,
    XCircle,
} from 'lucide-vue-next';
import { computed, ref, watch, type Component } from 'vue';

const props = withDefaults(
    defineProps<{
        status: ClaudeStatus;
        settings: ClaudeConnectionSettings;
        /** Auth modes the server supports. Defaults to all of them. */
        authModes?: { value: ClaudeAuthMode; label: string }[];
        submitLabel?: string;
    }>(),
    {
        authModes: undefined,
        submitLabel: 'Save',
    },
);

/** True while the form holds changes that haven't been saved. */
const dirty = defineModel<boolean>('dirty', { default: false });

interface AuthModeOption {
    value: ClaudeAuthMode;
    title: string;
    description: string;
    icon: Component;
}

const allAuthModeOptions: AuthModeOption[] = [
    {
        value: 'cli_login',
        title: 'Use my existing Claude Code login',
        description: "Reviews use whichever account you're logged into in the claude CLI on this computer. Nothing extra is stored.",
        icon: Terminal,
    },
    {
        value: 'api_key',
        title: 'Anthropic API key',
        description: 'Pay-as-you-go usage billed to your Anthropic Console account.',
        icon: KeyRound,
    },
    {
        value: 'oauth_token',
        title: 'Claude subscription token',
        description: 'Use your Claude Pro or Max plan with a long-lived token from claude setup-token.',
        icon: Ticket,
    },
];

const authModeOptions = computed(() =>
    props.authModes ? allAuthModeOptions.filter((option) => props.authModes!.some((mode) => mode.value === option.value)) : allAuthModeOptions,
);

const modelSuggestions = ['opus', 'sonnet', 'haiku', 'fable'];

const form = useForm({
    auth_mode: (props.settings.auth_mode ?? (props.status.logged_in ? 'cli_login' : null)) as ClaudeAuthMode | null,
    credential: '',
    model: props.settings.model ?? '',
    binary_path: props.settings.binary_path ?? '',
    max_budget_usd: props.settings.max_budget_usd?.toString() ?? '',
});

const needsCredential = computed(() => form.auth_mode === 'api_key' || form.auth_mode === 'oauth_token');

/** The saved key or token can be kept by leaving the field blank, as long as the auth mode is unchanged. */
const keepsSavedCredential = computed(() => Boolean(props.settings.has_credential) && form.auth_mode === props.settings.auth_mode);

const isConnected = computed(() => props.settings.auth_mode !== null);

/** How the claude CLI itself is logged in, as reported by `claude auth status`. */
const loginMethod = computed(() => (props.status.auth_method ? `via ${props.status.auth_method}` : null));

/** Also true while Claude isn't connected yet and an auth mode is picked for the user, so they can connect with one click. */
const hasUnsavedChanges = computed(() => form.isDirty || form.auth_mode !== props.settings.auth_mode);

/** Only the user's own edits are worth a warning, not the auth mode picked for them. */
useUnsavedChangesGuard(() => form.isDirty);

const advancedOpen = ref(Boolean(props.settings.model || props.settings.binary_path || props.settings.max_budget_usd) || !props.status.installed);

const checking = ref(false);
const confirmingDisconnect = ref(false);
const disconnecting = ref(false);
const disconnectError = ref<string | null>(null);

watch(hasUnsavedChanges, (value) => (dirty.value = value), { immediate: true });

watch(
    () => form.auth_mode,
    () => {
        form.credential = '';
        form.clearErrors('credential');
    },
);

watch(
    () => [form.errors.model, form.errors.binary_path, form.errors.max_budget_usd],
    (errors) => {
        if (errors.some(Boolean)) {
            advancedOpen.value = true;
        }
    },
);

function save(): void {
    form.put(route('settings.claude.update'), {
        preserveScroll: true,
        onSuccess: () => {
            form.credential = '';
            form.defaults();
        },
    });
}

function checkAgain(): void {
    router.reload({
        only: ['status'],
        onStart: () => (checking.value = true),
        onFinish: () => (checking.value = false),
    });
}

function disconnect(): void {
    router.put(
        route('settings.claude.update'),
        {
            auth_mode: null,
            credential: null,
            model: props.settings.model ?? null,
            binary_path: props.settings.binary_path ?? null,
            max_budget_usd: props.settings.max_budget_usd ?? null,
        },
        {
            preserveScroll: true,
            onStart: () => {
                disconnecting.value = true;
                disconnectError.value = null;
            },
            onSuccess: () => {
                confirmingDisconnect.value = false;
                form.auth_mode = null;
                form.credential = '';
                form.defaults();
            },
            onError: (errors) => (disconnectError.value = Object.values(errors)[0] ?? 'Claude could not be disconnected.'),
            onFinish: () => (disconnecting.value = false),
        },
    );
}
</script>

<script lang="ts">
import type { ClaudeAuthMode } from '@/types';

/** Saved Claude settings. During onboarding only `auth_mode` is known. */
export interface ClaudeConnectionSettings {
    auth_mode: ClaudeAuthMode | null;
    has_credential?: boolean | null;
    model?: string | null;
    binary_path?: string | null;
    max_budget_usd?: number | null;
}
</script>

<template>
    <div class="space-y-8">
        <section class="space-y-3">
            <div class="flex items-center justify-between gap-3">
                <h4 class="text-sm font-medium">Claude Code on this computer</h4>
                <Button type="button" variant="ghost" size="sm" :disabled="checking" @click="checkAgain">
                    <RefreshCw :class="{ 'animate-spin': checking }" />
                    Check again
                </Button>
            </div>

            <div
                :class="
                    cn(
                        'flex items-start gap-3 rounded-lg border p-4',
                        status.error ? 'border-red-200 bg-red-50/60 dark:border-red-500/30 dark:bg-red-500/10' : 'bg-muted/30',
                    )
                "
            >
                <XCircle v-if="status.error" class="mt-0.5 size-5 shrink-0 text-red-600 dark:text-red-500" />
                <CheckCircle2 v-else class="mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-500" />

                <div class="min-w-0 flex-1 space-y-2 text-sm">
                    <template v-if="status.installed && !status.error">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">Claude Code is installed</span>
                            <Badge v-if="status.version" variant="muted" class="font-mono">{{ status.version }}</Badge>
                        </div>
                        <p v-if="status.binary" class="break-all font-mono text-xs text-muted-foreground">{{ status.binary }}</p>
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge v-if="status.logged_in" variant="success">Logged in</Badge>
                            <Badge v-else-if="status.logged_in === false" variant="warning">Not logged in</Badge>
                            <Badge v-else variant="muted">Login status unknown</Badge>
                            <span v-if="status.logged_in && loginMethod" class="text-xs text-muted-foreground">{{ loginMethod }}</span>
                            <span v-else-if="status.logged_in === false" class="text-xs text-muted-foreground">
                                Run <code class="rounded bg-muted px-1 py-0.5 font-mono">claude</code> in a terminal to log in, or use a key or token
                                below.
                            </span>
                        </div>
                    </template>

                    <template v-else>
                        <p class="font-medium">{{ status.installed ? "Claude Code couldn't be started" : "Claude Code wasn't found" }}</p>
                        <p v-if="status.binary" class="break-all font-mono text-xs text-muted-foreground">{{ status.binary }}</p>
                        <p class="whitespace-pre-line break-words text-muted-foreground">{{ status.error }}</p>
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                            <ExternalLink
                                href="https://claude.com/claude-code"
                                class="inline-flex items-center gap-1 font-medium text-primary underline-offset-4 hover:underline"
                            >
                                Install Claude Code
                                <ExternalLinkIcon class="size-3.5" />
                            </ExternalLink>
                            <span class="text-xs text-muted-foreground">Installed somewhere unusual? Set its path under Advanced.</span>
                        </div>
                    </template>
                </div>
            </div>
        </section>

        <form class="space-y-6" @submit.prevent="save">
            <fieldset class="space-y-3">
                <legend class="mb-3 text-sm font-medium">How should reviews sign in to Claude?</legend>

                <label
                    v-for="option in authModeOptions"
                    :key="option.value"
                    :class="
                        cn(
                            'relative flex cursor-pointer gap-3 rounded-lg border p-4 transition-colors has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring has-[:focus-visible]:ring-offset-2 has-[:focus-visible]:ring-offset-background',
                            form.auth_mode === option.value ? 'border-primary bg-primary/[0.04]' : 'hover:bg-muted/40',
                        )
                    "
                >
                    <input v-model="form.auth_mode" type="radio" name="claude-auth-mode" class="sr-only" :value="option.value" />
                    <span
                        :class="
                            cn(
                                'mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border',
                                form.auth_mode === option.value ? 'border-primary' : 'border-input',
                            )
                        "
                    >
                        <span v-if="form.auth_mode === option.value" class="size-2 rounded-full bg-primary" />
                    </span>
                    <div class="min-w-0 flex-1 space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <component :is="option.icon" class="size-4 text-muted-foreground" />
                            <span class="text-sm font-medium">{{ option.title }}</span>
                            <Badge v-if="option.value === 'cli_login' && status.logged_in" variant="success">Recommended</Badge>
                            <Badge v-if="settings.auth_mode === option.value" variant="outline">Current</Badge>
                        </div>
                        <p class="text-sm text-muted-foreground">{{ option.description }}</p>
                    </div>
                </label>

                <InputError :message="form.errors.auth_mode" />
            </fieldset>

            <div
                v-if="form.auth_mode === 'cli_login' && status.logged_in === false"
                class="flex gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                <p>
                    Claude Code isn't logged in on this computer yet. Open a terminal, run
                    <code class="rounded bg-amber-100 px-1 py-0.5 font-mono dark:bg-amber-500/20">claude</code> and complete the login, then click
                    <span class="font-medium">Check again</span>.
                </p>
            </div>

            <div v-if="needsCredential" class="space-y-2 rounded-lg border bg-muted/30 p-4">
                <template v-if="form.auth_mode === 'api_key'">
                    <Label for="claude-credential">API key</Label>
                    <p class="text-sm text-muted-foreground">
                        Create a key in the
                        <ExternalLink
                            href="https://console.anthropic.com/settings/keys"
                            class="font-medium text-primary underline-offset-4 hover:underline"
                        >
                            Anthropic Console
                        </ExternalLink>
                        and paste it here. It starts with <code class="font-mono text-xs">sk-ant-api</code>.
                    </p>
                </template>
                <template v-else>
                    <Label for="claude-credential">Subscription token</Label>
                    <ol class="list-decimal space-y-1 pl-5 text-sm text-muted-foreground">
                        <li>
                            Open a terminal and run
                            <code class="rounded bg-muted px-1 py-0.5 font-mono text-xs text-foreground">claude setup-token</code>.
                        </li>
                        <li>Sign in with your Claude account when your browser opens.</li>
                        <li>Paste the token it prints (it starts with <code class="font-mono text-xs">sk-ant-oat</code>).</li>
                    </ol>
                </template>
                <Input
                    id="claude-credential"
                    v-model="form.credential"
                    type="password"
                    autocomplete="off"
                    spellcheck="false"
                    class="font-mono"
                    :placeholder="keepsSavedCredential ? '•••••••••••• saved' : form.auth_mode === 'api_key' ? 'sk-ant-api…' : 'sk-ant-oat…'"
                />
                <p v-if="keepsSavedCredential" class="text-xs text-muted-foreground">
                    Leave blank to keep the saved {{ form.auth_mode === 'api_key' ? 'key' : 'token' }}.
                </p>
                <p class="text-xs text-muted-foreground">Stored encrypted on this computer and only passed to Claude Code when a review runs.</p>
                <InputError :message="form.errors.credential" />
            </div>

            <Collapsible v-model:open="advancedOpen" class="rounded-lg border">
                <CollapsibleTrigger class="flex w-full items-center justify-between gap-2 px-4 py-3 text-left text-sm font-medium">
                    Advanced
                    <ChevronDown :class="cn('size-4 text-muted-foreground transition-transform', advancedOpen && 'rotate-180')" />
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <div class="grid gap-5 border-t px-4 py-4">
                        <div class="grid gap-2">
                            <Label for="claude-model">Model</Label>
                            <Input
                                id="claude-model"
                                v-model="form.model"
                                list="claude-model-suggestions"
                                placeholder="Claude Code default"
                                autocomplete="off"
                            />
                            <datalist id="claude-model-suggestions">
                                <option v-for="model in modelSuggestions" :key="model" :value="model" />
                            </datalist>
                            <p class="text-xs text-muted-foreground">
                                An alias such as opus or sonnet, or a full model name. Leave blank to use Claude Code's default.
                            </p>
                            <InputError :message="form.errors.model" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="claude-binary-path">Claude Code path</Label>
                            <Input
                                id="claude-binary-path"
                                v-model="form.binary_path"
                                class="font-mono"
                                spellcheck="false"
                                :placeholder="status.binary ?? '/usr/local/bin/claude'"
                                autocomplete="off"
                            />
                            <p class="text-xs text-muted-foreground">
                                Only needed if Claude Code isn't found automatically. Run
                                <code class="rounded bg-muted px-1 py-0.5 font-mono">which claude</code> in a terminal to find it.
                            </p>
                            <InputError :message="form.errors.binary_path" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="claude-max-budget">Maximum spend per review</Label>
                            <div class="relative sm:max-w-48">
                                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">$</span>
                                <Input
                                    id="claude-max-budget"
                                    v-model="form.max_budget_usd"
                                    type="number"
                                    min="0.01"
                                    max="1000"
                                    step="0.01"
                                    placeholder="No limit"
                                    class="pl-7"
                                />
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Claude Code stops a review once it has spent this much (USD). Leave blank for no limit.
                            </p>
                            <InputError :message="form.errors.max_budget_usd" />
                        </div>
                    </div>
                </CollapsibleContent>
            </Collapsible>

            <div class="flex flex-wrap items-center gap-3">
                <Button type="submit" :disabled="form.processing || form.auth_mode === null || !hasUnsavedChanges">
                    <Loader2 v-if="form.processing" class="animate-spin" />
                    {{ submitLabel }}
                </Button>
                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p v-show="form.recentlySuccessful" class="text-sm text-muted-foreground">Saved.</p>
                </Transition>
                <Button
                    v-if="isConnected"
                    type="button"
                    variant="ghost"
                    class="ml-auto text-red-600 hover:bg-red-50 hover:text-red-700 dark:text-red-500 dark:hover:bg-red-500/10"
                    @click="confirmingDisconnect = true"
                >
                    Disconnect Claude
                </Button>
            </div>
        </form>

        <Dialog v-model:open="confirmingDisconnect">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Disconnect Claude?</DialogTitle>
                    <DialogDescription>
                        AI reviews and drafted replies stop working until you connect Claude again.
                        <template v-if="settings.has_credential"> The saved key or token is deleted from this computer.</template>
                    </DialogDescription>
                </DialogHeader>
                <InputError :message="disconnectError ?? undefined" />
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
    </div>
</template>
