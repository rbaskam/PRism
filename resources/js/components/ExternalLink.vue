<script setup lang="ts">
import { useExternalLink } from '@/composables/useExternalLink';

const props = defineProps<{
    href: string | null | undefined;
}>();

const { openExternal } = useExternalLink();
</script>

<template>
    <a :href="props.href ?? '#'" rel="noopener" target="_blank" @click.prevent.stop="openExternal(props.href)">
        <slot />
    </a>
</template>
