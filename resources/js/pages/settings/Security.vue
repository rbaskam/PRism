<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Switch } from '@/components/ui/switch';
import { useUnsavedChangesGuard } from '@/composables/useUnsavedChangesGuard';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Fingerprint, Info, KeyRound, Loader2, Lock } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    settings: {
        lock_enabled: boolean;
        has_pin: boolean;
        use_biometrics: boolean;
        auto_lock_minutes: number;
        lock_on_launch: boolean;
    };
    biometrics: { available: boolean; label: string | null };
    /** Minutes of inactivity before the app locks; 0 means never. */
    autoLockOptions: number[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Security settings', href: route('settings.security') }];

const MAX_PIN_LENGTH = 8;

const form = useForm({
    lock_enabled: props.settings.lock_enabled,
    pin: '',
    pin_confirmation: '',
    current_pin: '',
    use_biometrics: props.settings.use_biometrics,
    auto_lock_minutes: props.settings.auto_lock_minutes,
    lock_on_launch: props.settings.lock_on_launch,
});

useUnsavedChangesGuard(() => form.isDirty);

const changingPin = ref(false);
const locking = ref(false);

const biometricsLabel = computed(() => props.biometrics.label ?? 'Touch ID');
const shortcutLabel = navigator.platform.toLowerCase().includes('mac') ? '⌘L' : 'Ctrl+L';

/** A new PIN is needed the first time the lock is turned on. */
const needsNewPin = computed(() => form.lock_enabled && !props.settings.has_pin);
const showPinFields = computed(() => form.lock_enabled && (needsNewPin.value || changingPin.value));
const isTurningOff = computed(() => props.settings.lock_enabled && !form.lock_enabled);

/** The server asks for the current PIN before the lock is turned off or the PIN is changed. */
const changingPinNeedsCurrentPin = computed(() => props.settings.lock_enabled && form.lock_enabled && changingPin.value);
const showCurrentPin = computed(() => isTurningOff.value || changingPinNeedsCurrentPin.value);

const pinsDiffer = computed(() => form.pin_confirmation !== '' && form.pin !== form.pin_confirmation);
const pinConfirmationError = computed(() => form.errors.pin_confirmation ?? (pinsDiffer.value ? "The PINs don't match." : undefined));

for (const field of ['pin', 'pin_confirmation', 'current_pin'] as const) {
    watch(
        () => form[field],
        (value) => {
            const digits = value.replace(/\D/g, '').slice(0, MAX_PIN_LENGTH);

            if (digits !== value) {
                form[field] = digits;
            }
        },
    );
}

watch(showPinFields, (visible) => {
    if (!visible) {
        form.pin = '';
        form.pin_confirmation = '';
    }
});

watch(showCurrentPin, (visible) => {
    if (!visible) {
        form.current_pin = '';
    }
});

function autoLockLabel(minutes: number): string {
    if (minutes === 0) {
        return 'Never';
    }

    if (minutes % 60 === 0) {
        return minutes === 60 ? '1 hour' : `${minutes / 60} hours`;
    }

    return minutes === 1 ? '1 minute' : `${minutes} minutes`;
}

function save(): void {
    form.put(route('settings.security.update'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('pin', 'pin_confirmation', 'current_pin');
            form.defaults();
            changingPin.value = false;
        },
        onError: (errors) => {
            if (errors.current_pin) {
                form.reset('current_pin');
            }
        },
    });
}

function lockNow(): void {
    router.post(
        route('lock.store'),
        {},
        {
            onStart: () => (locking.value = true),
            onFinish: () => (locking.value = false),
        },
    );
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Security settings" />

        <SettingsLayout>
            <form class="space-y-6" @submit.prevent="save">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <HeadingSmall title="App lock" :description="`Require a PIN to open ${$page.props.name} on this computer.`" />
                    <Button
                        v-if="settings.lock_enabled"
                        type="button"
                        variant="outline"
                        size="sm"
                        class="shrink-0"
                        :disabled="locking"
                        @click="lockNow"
                    >
                        <Loader2 v-if="locking" class="animate-spin" />
                        <Lock v-else />
                        Lock now
                        <kbd class="ml-1 rounded border bg-muted px-1 font-mono text-[10px] text-muted-foreground">{{ shortcutLabel }}</kbd>
                    </Button>
                </div>

                <div class="flex items-start gap-3 rounded-lg border bg-muted/40 p-4 text-sm text-muted-foreground">
                    <Info class="mt-0.5 size-4 shrink-0" />
                    <p>
                        The lock protects the app's screens on this computer. While it's locked, {{ $page.props.name }} only shows the lock screen
                        until you enter your PIN{{ biometrics.available ? ` or use ${biometricsLabel}` : '' }}. Your PIN is stored as a one-way hash,
                        never as plain text. The lock doesn't encrypt your data and doesn't change anything on GitHub.
                    </p>
                </div>

                <div class="divide-y rounded-lg border">
                    <div class="flex items-start justify-between gap-4 p-4">
                        <div class="space-y-1">
                            <Label for="lock_enabled" class="cursor-pointer">Lock the app</Label>
                            <p class="text-sm text-muted-foreground">Show the lock screen when the app starts or after you've been away.</p>
                            <InputError :message="form.errors.lock_enabled" />
                        </div>
                        <Switch id="lock_enabled" v-model:checked="form.lock_enabled" class="mt-0.5" />
                    </div>

                    <div v-if="isTurningOff" class="space-y-2 p-4">
                        <Label for="current_pin">Current PIN</Label>
                        <p class="text-sm text-muted-foreground">Enter your current PIN to turn off the lock.</p>
                        <Input
                            id="current_pin"
                            v-model="form.current_pin"
                            type="password"
                            inputmode="numeric"
                            autocomplete="off"
                            :maxlength="MAX_PIN_LENGTH"
                            class="font-mono tracking-widest sm:max-w-48"
                        />
                        <InputError :message="form.errors.current_pin" />
                    </div>

                    <template v-if="form.lock_enabled">
                        <div class="space-y-4 p-4">
                            <div class="flex items-start justify-between gap-4">
                                <div class="space-y-1">
                                    <p class="flex items-center gap-2 text-sm font-medium">
                                        <KeyRound class="size-4 text-muted-foreground" />
                                        {{ needsNewPin ? 'Set a PIN' : 'PIN' }}
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        {{ needsNewPin ? 'Choose 4 to 8 digits. You need it to unlock the app.' : 'Your PIN is set.' }}
                                    </p>
                                </div>
                                <Button
                                    v-if="!needsNewPin"
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    class="shrink-0"
                                    @click="changingPin = !changingPin"
                                >
                                    {{ changingPin ? 'Cancel' : 'Change PIN' }}
                                </Button>
                            </div>

                            <div v-if="showPinFields" class="grid gap-4 sm:grid-cols-2">
                                <div v-if="changingPinNeedsCurrentPin" class="grid gap-2 sm:col-span-2">
                                    <Label for="current_pin">Current PIN</Label>
                                    <Input
                                        id="current_pin"
                                        v-model="form.current_pin"
                                        type="password"
                                        inputmode="numeric"
                                        autocomplete="off"
                                        :maxlength="MAX_PIN_LENGTH"
                                        class="font-mono tracking-widest sm:max-w-48"
                                    />
                                    <InputError :message="form.errors.current_pin" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="pin">{{ needsNewPin ? 'PIN' : 'New PIN' }}</Label>
                                    <Input
                                        id="pin"
                                        v-model="form.pin"
                                        type="password"
                                        inputmode="numeric"
                                        autocomplete="new-password"
                                        :maxlength="MAX_PIN_LENGTH"
                                        placeholder="4–8 digits"
                                        class="font-mono tracking-widest placeholder:font-sans placeholder:tracking-normal"
                                    />
                                    <InputError :message="form.errors.pin" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="pin_confirmation">Confirm PIN</Label>
                                    <Input
                                        id="pin_confirmation"
                                        v-model="form.pin_confirmation"
                                        type="password"
                                        inputmode="numeric"
                                        autocomplete="new-password"
                                        :maxlength="MAX_PIN_LENGTH"
                                        class="font-mono tracking-widest"
                                    />
                                    <InputError :message="pinConfirmationError" />
                                </div>
                            </div>
                            <InputError v-else :message="form.errors.pin ?? form.errors.current_pin" />
                        </div>

                        <div class="flex items-start justify-between gap-4 p-4">
                            <div class="space-y-1">
                                <Label
                                    :for="biometrics.available ? 'use_biometrics' : undefined"
                                    :class="cn('flex items-center gap-2', biometrics.available && 'cursor-pointer')"
                                >
                                    <Fingerprint class="size-4 text-muted-foreground" />
                                    Unlock with {{ biometricsLabel }}
                                </Label>
                                <p v-if="biometrics.available" class="text-sm text-muted-foreground">
                                    Use your fingerprint instead of typing your PIN. The PIN always works as a fallback.
                                </p>
                                <p v-else class="text-sm text-muted-foreground">
                                    Touch ID works in the desktop app on Macs with a fingerprint reader. Face ID will come with a future mobile app.
                                    Until then, unlock with your PIN.
                                </p>
                                <InputError :message="form.errors.use_biometrics" />
                            </div>
                            <Switch v-if="biometrics.available" id="use_biometrics" v-model:checked="form.use_biometrics" class="mt-0.5" />
                        </div>

                        <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between">
                            <div class="space-y-1">
                                <Label for="auto_lock_minutes">Auto-lock after</Label>
                                <p class="text-sm text-muted-foreground">Lock the app when you haven't used it for a while.</p>
                                <InputError :message="form.errors.auto_lock_minutes" />
                            </div>
                            <NativeSelect
                                id="auto_lock_minutes"
                                :model-value="form.auto_lock_minutes"
                                class="shrink-0 sm:w-40"
                                @update:model-value="(value) => (form.auto_lock_minutes = Number(value))"
                            >
                                <option v-for="minutes in autoLockOptions" :key="minutes" :value="minutes">{{ autoLockLabel(minutes) }}</option>
                            </NativeSelect>
                        </div>

                        <div class="flex items-start justify-between gap-4 p-4">
                            <div class="space-y-1">
                                <Label for="lock_on_launch" class="cursor-pointer">Lock when the app starts</Label>
                                <p class="text-sm text-muted-foreground">Ask for your PIN every time {{ $page.props.name }} opens.</p>
                                <InputError :message="form.errors.lock_on_launch" />
                            </div>
                            <Switch id="lock_on_launch" v-model:checked="form.lock_on_launch" class="mt-0.5" />
                        </div>
                    </template>
                </div>

                <div class="flex items-center gap-3">
                    <Button type="submit" :disabled="form.processing || !form.isDirty || pinsDiffer">
                        <Loader2 v-if="form.processing" class="animate-spin" />
                        Save
                    </Button>
                    <Transition
                        enter-active-class="transition ease-in-out"
                        enter-from-class="opacity-0"
                        leave-active-class="transition ease-in-out"
                        leave-to-class="opacity-0"
                    >
                        <p v-show="form.recentlySuccessful" class="text-sm text-muted-foreground">Saved.</p>
                    </Transition>
                </div>
            </form>
        </SettingsLayout>
    </AppLayout>
</template>
