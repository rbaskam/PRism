<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Info } from 'lucide-vue-next';

/** "Posting is off — enable in Settings → PR review", for actions the user has switched off. */
withDefaults(
    defineProps<{
        message: string;
        href?: string;
        linkLabel?: string;
    }>(),
    {
        href: () => route('settings.review'),
        linkLabel: 'enable in Settings → PR review',
    },
);
</script>

<template>
    <p class="flex items-center gap-1.5 text-xs text-muted-foreground">
        <Info class="size-3.5 shrink-0" />
        <span>
            {{ message }} —
            <Link :href="href" class="font-medium text-foreground underline underline-offset-2 hover:no-underline">{{ linkLabel }}</Link>
        </span>
    </p>
</template>
