<script setup lang="ts">
import { formatDuration } from '@/lib/pull-requests';
import { useNow } from '@vueuse/core';
import { computed } from 'vue';

const props = defineProps<{
    since: string | null;
}>();

const now = useNow({ interval: 1000 });

const elapsed = computed(() => (props.since ? formatDuration(Math.max(0, now.value.getTime() - new Date(props.since).getTime())) : ''));
</script>

<template>
    <span class="tabular-nums">{{ elapsed }}</span>
</template>
