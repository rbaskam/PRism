<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { computed } from 'vue';
import GithubMarkdown from './GithubMarkdown.vue';
import { renderMarkdown } from './markdown';

/**
 * Markdown written by Claude (plain text). It is escaped and rendered with the small renderer in markdown.ts,
 * never inserted as raw HTML.
 */
const props = defineProps<{
    text: string;
    class?: HTMLAttributes['class'];
}>();

const html = computed(() => renderMarkdown(props.text));
</script>

<template>
    <GithubMarkdown :html="html" :class="props.class" />
</template>
