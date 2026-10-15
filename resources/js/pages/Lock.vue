<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import FlashMessages from '@/components/FlashMessages.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Delete, Fingerprint, Loader2, Lock, Timer } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    biometrics: { enabled: boolean; label: string | null };
    /** Greater than 0 after too many wrong PINs: entry is blocked for this many seconds. */
    lockedOutSeconds: number;
}>();

const page = usePage<SharedData>();

const MAX_PIN_LENGTH = 8;
const MIN_PIN_LENGTH = 4;
const keypadDigits = ['1', '2', '3', '4', '5', '6', '7', '8', '9'];

const form = useForm({ pin: '' });
const pinField = ref<HTMLElement | null>(null);
const unlockingWithBiometrics = ref(false);
const secondsLeft = ref(0);

const biometricsLabel = computed(() => props.biometrics.label ?? 'Touch ID');
const isLockedOut = computed(() => secondsLeft.value > 0);
const canSubmit = computed(() => form.pin.length >= MIN_PIN_LENGTH && !form.processing && !isLockedOut.value);
const countdown = computed(() => {
    const minutes = Math.floor(secondsLeft.value / 60);
    const seconds = secondsLeft.value % 60;

    return `${minutes}:${String(seconds).padStart(2, '0')}`;
});

let countdownTimer: ReturnType<typeof setInterval> | undefined;

function focusPin(): void {
    nextTick(() => document.getElementById('pin')?.focus());
}

function shakePin(): void {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    pinField.value?.animate(
        [
            { transform: 'translateX(0)' },
            { transform: 'translateX(-10px)' },
            { transform: 'translateX(10px)' },
            { transform: 'translateX(-6px)' },
            { transform: 'translateX(6px)' },
            { transform: 'translateX(0)' },
        ],
        { duration: 400, easing: 'ease-in-out' },
    );
}

function startCountdown(seconds: number): void {
    clearInterval(countdownTimer);
    secondsLeft.value = Math.max(0, Math.ceil(seconds));

    if (secondsLeft.value === 0) {
        return;
    }

    countdownTimer = setInterval(() => {
        secondsLeft.value = Math.max(0, secondsLeft.value - 1);

        if (secondsLeft.value === 0) {
            clearInterval(countdownTimer);
            form.clearErrors('pin');
            focusPin();
        }
    }, 1000);
}

watch(() => props.lockedOutSeconds, startCountdown, { immediate: true });

watch(
    () => form.pin,
    (pin) => {
        const digits = pin.replace(/\D/g, '').slice(0, MAX_PIN_LENGTH);

        if (digits !== pin) {
            form.pin = digits;
        }

        if (digits !== '' && form.errors.pin) {
            form.clearErrors('pin');
        }
    },
);

function pressDigit(digit: string): void {
    if (form.pin.length < MAX_PIN_LENGTH && !isLockedOut.value) {
        form.pin += digit;
    }

    focusPin();
}

function deleteDigit(): void {
    form.pin = form.pin.slice(0, -1);
    focusPin();
}

/** preserveState keeps this component mounted when the server redirects back, so a failure doesn't re-trigger Touch ID. */
function unlockWithPin(): void {
    if (!canSubmit.value) {
        return;
    }

    form.post(route('lock.pin'), {
        preserveState: true,
        onError: () => {
            form.reset('pin');
            shakePin();
            focusPin();
        },
    });
}

function unlockWithBiometrics(): void {
    router.post(
        route('lock.biometrics'),
        {},
        {
            preserveState: true,
            onStart: () => (unlockingWithBiometrics.value = true),
            onFinish: () => {
                unlockingWithBiometrics.value = false;
                focusPin();
            },
        },
    );
}

onMounted(() => {
    focusPin();

    if (props.biometrics.enabled) {
        unlockWithBiometrics();
    }
});

onBeforeUnmount(() => clearInterval(countdownTimer));
</script>

<template>
    <div class="flex min-h-svh flex-col items-center justify-center bg-muted/40 px-4 py-10">
        <Head title="Locked" />

        <div class="w-full max-w-sm">
            <div class="mb-8 flex items-center justify-center gap-2.5">
                <AppLogoIcon class="size-9" />
                <span class="font-semibold">{{ page.props.name }}</span>
            </div>

            <div class="rounded-xl border bg-background p-6 shadow-sm sm:p-8">
                <div class="flex flex-col items-center gap-3 text-center">
                    <div class="flex size-12 items-center justify-center rounded-full bg-muted">
                        <Lock class="size-5" />
                    </div>
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold tracking-tight">{{ page.props.name }} is locked</h1>
                        <p class="text-sm text-muted-foreground">
                            {{ biometrics.enabled ? `Use ${biometricsLabel} or enter your PIN to continue.` : 'Enter your PIN to continue.' }}
                        </p>
                    </div>
                </div>

                <div v-if="biometrics.enabled" class="mt-6 space-y-4">
                    <Button type="button" variant="outline" class="h-11 w-full" :disabled="unlockingWithBiometrics" @click="unlockWithBiometrics">
                        <Loader2 v-if="unlockingWithBiometrics" class="animate-spin" />
                        <Fingerprint v-else />
                        {{ unlockingWithBiometrics ? `Waiting for ${biometricsLabel}…` : `Unlock with ${biometricsLabel}` }}
                    </Button>
                    <div class="flex items-center gap-3 text-xs text-muted-foreground">
                        <div class="h-px flex-1 bg-border" />
                        or use your PIN
                        <div class="h-px flex-1 bg-border" />
                    </div>
                </div>

                <form class="mt-6 space-y-4" @submit.prevent="unlockWithPin">
                    <div ref="pinField" class="space-y-2">
                        <Label for="pin" class="sr-only">PIN</Label>
                        <Input
                            id="pin"
                            v-model="form.pin"
                            type="password"
                            inputmode="numeric"
                            pattern="[0-9]*"
                            autocomplete="off"
                            :maxlength="MAX_PIN_LENGTH"
                            :disabled="isLockedOut"
                            placeholder="PIN"
                            :aria-invalid="Boolean(form.errors.pin)"
                            class="h-12 text-center font-mono text-2xl tracking-[0.5em] placeholder:font-sans placeholder:text-base placeholder:tracking-normal md:text-2xl"
                        />
                        <p
                            v-if="isLockedOut"
                            class="flex items-center justify-center gap-1.5 text-sm text-amber-700 dark:text-amber-400"
                            role="status"
                        >
                            <Timer class="size-4" />
                            Too many wrong PINs. Try again in <span class="font-mono tabular-nums">{{ countdown }}</span>
                        </p>
                        <InputError v-else :message="form.errors.pin" class="text-center" />
                    </div>

                    <div class="grid grid-cols-3 gap-2">
                        <Button
                            v-for="digit in keypadDigits"
                            :key="digit"
                            type="button"
                            variant="secondary"
                            class="h-12 text-lg font-medium"
                            tabindex="-1"
                            :disabled="isLockedOut"
                            @mousedown.prevent
                            @click="pressDigit(digit)"
                        >
                            {{ digit }}
                        </Button>
                        <div />
                        <Button
                            type="button"
                            variant="secondary"
                            class="h-12 text-lg font-medium"
                            tabindex="-1"
                            :disabled="isLockedOut"
                            @mousedown.prevent
                            @click="pressDigit('0')"
                        >
                            0
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            class="h-12"
                            tabindex="-1"
                            :disabled="isLockedOut || form.pin === ''"
                            @mousedown.prevent
                            @click="deleteDigit"
                        >
                            <Delete class="!size-5" />
                            <span class="sr-only">Delete last digit</span>
                        </Button>
                    </div>

                    <Button type="submit" class="h-11 w-full" :disabled="!canSubmit">
                        <Loader2 v-if="form.processing" class="animate-spin" />
                        Unlock
                    </Button>
                </form>
            </div>
        </div>

        <FlashMessages />
    </div>
</template>
