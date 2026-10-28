<script setup lang="ts">
import { useExternalLink } from '@/composables/useExternalLink';
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

/**
 * Renders HTML that GitHub already rendered and sanitised (descriptions, comments, reviews).
 * Links inside it open in the system browser instead of navigating the app window.
 */
const props = defineProps<{
    html: string;
    class?: HTMLAttributes['class'];
}>();

const { openExternal } = useExternalLink();

function handleClick(event: MouseEvent): void {
    const anchor = (event.target as HTMLElement | null)?.closest('a');
    const href = anchor?.getAttribute('href');

    if (!anchor || !href || href.startsWith('#')) {
        return;
    }

    event.preventDefault();
    openExternal(href.startsWith('/') ? `https://github.com${href}` : anchor.href);
}
</script>

<template>
    <div :class="cn('github-markdown', props.class)" @click="handleClick" v-html="props.html" />
</template>

<style scoped>
.github-markdown {
    font-size: 0.875rem;
    line-height: 1.6;
    overflow-wrap: anywhere;
}

.github-markdown > :deep(*:first-child) {
    margin-top: 0 !important;
}

.github-markdown > :deep(*:last-child) {
    margin-bottom: 0 !important;
}

.github-markdown :deep(p),
.github-markdown :deep(ul),
.github-markdown :deep(ol),
.github-markdown :deep(pre),
.github-markdown :deep(blockquote),
.github-markdown :deep(table),
.github-markdown :deep(details),
.github-markdown :deep(.markdown-alert) {
    margin-top: 0;
    margin-bottom: 0.75rem;
}

.github-markdown :deep(h1),
.github-markdown :deep(h2),
.github-markdown :deep(h3),
.github-markdown :deep(h4),
.github-markdown :deep(h5),
.github-markdown :deep(h6) {
    margin-top: 1.25rem;
    margin-bottom: 0.5rem;
    font-weight: 600;
    line-height: 1.3;
}

.github-markdown :deep(h1) {
    font-size: 1.25rem;
    padding-bottom: 0.25rem;
    border-bottom: 1px solid hsl(var(--border));
}

.github-markdown :deep(h2) {
    font-size: 1.125rem;
    padding-bottom: 0.25rem;
    border-bottom: 1px solid hsl(var(--border));
}

.github-markdown :deep(h3) {
    font-size: 1rem;
}

.github-markdown :deep(h4),
.github-markdown :deep(h5),
.github-markdown :deep(h6) {
    font-size: 0.875rem;
}

.github-markdown :deep(a) {
    color: hsl(var(--primary));
    text-decoration: underline;
    text-underline-offset: 2px;
    text-decoration-color: hsl(var(--primary) / 0.35);
    cursor: pointer;
}

.github-markdown :deep(a.user-mention),
.github-markdown :deep(a.team-mention) {
    font-weight: 600;
    text-decoration: none;
}

.github-markdown :deep(strong) {
    font-weight: 600;
}

.github-markdown :deep(ul),
.github-markdown :deep(ol) {
    padding-left: 1.5rem;
}

.github-markdown :deep(ul) {
    list-style: disc;
}

.github-markdown :deep(ol) {
    list-style: decimal;
}

.github-markdown :deep(li + li) {
    margin-top: 0.25rem;
}

.github-markdown :deep(li > ul),
.github-markdown :deep(li > ol) {
    margin-top: 0.25rem;
    margin-bottom: 0;
}

.github-markdown :deep(ul.contains-task-list) {
    list-style: none;
    padding-left: 0.25rem;
}

.github-markdown :deep(.task-list-item-checkbox) {
    margin: 0 0.375rem 0.125rem 0;
    vertical-align: middle;
}

.github-markdown :deep(code),
.github-markdown :deep(kbd),
.github-markdown :deep(tt) {
    font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace;
    font-size: 0.8125em;
    padding: 0.125rem 0.375rem;
    border-radius: 0.25rem;
    background: hsl(var(--muted));
    border: 1px solid hsl(var(--border));
}

.github-markdown :deep(pre) {
    font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace;
    font-size: 0.75rem;
    line-height: 1.5;
    padding: 0.75rem;
    overflow-x: auto;
    border-radius: 0.375rem;
    background: hsl(var(--muted));
    border: 1px solid hsl(var(--border));
}

.github-markdown :deep(pre code) {
    padding: 0;
    font-size: inherit;
    background: transparent;
    border: 0;
}

.github-markdown :deep(.highlight) {
    margin-bottom: 0.75rem;
}

.github-markdown :deep(.highlight pre) {
    margin-bottom: 0;
}

.github-markdown :deep(blockquote) {
    padding: 0 0.875rem;
    color: hsl(var(--muted-foreground));
    border-left: 3px solid hsl(var(--border));
}

.github-markdown :deep(.markdown-alert) {
    padding: 0.5rem 0.875rem;
    border-left: 3px solid rgb(59 130 246);
}

.github-markdown :deep(.markdown-alert-warning),
.github-markdown :deep(.markdown-alert-caution) {
    border-left-color: rgb(245 158 11);
}

.github-markdown :deep(.markdown-alert-important) {
    border-left-color: rgb(139 92 246);
}

.github-markdown :deep(.markdown-alert-tip) {
    border-left-color: rgb(16 185 129);
}

.github-markdown :deep(.markdown-alert-title) {
    font-weight: 600;
    margin-bottom: 0.25rem;
}

.github-markdown :deep(.markdown-alert-title svg),
.github-markdown :deep(.octicon) {
    display: inline-block;
    vertical-align: text-bottom;
    fill: currentColor;
    margin-right: 0.25rem;
}

.github-markdown :deep(hr) {
    margin: 1rem 0;
    border: 0;
    border-top: 1px solid hsl(var(--border));
}

.github-markdown :deep(img) {
    display: inline-block;
    max-width: 100%;
    height: auto;
    border-radius: 0.25rem;
}

.github-markdown :deep(video) {
    max-width: 100%;
    border-radius: 0.375rem;
}

.github-markdown :deep(table) {
    display: block;
    width: max-content;
    max-width: 100%;
    overflow-x: auto;
    border-collapse: collapse;
    font-size: 0.8125rem;
}

.github-markdown :deep(th),
.github-markdown :deep(td) {
    padding: 0.375rem 0.75rem;
    border: 1px solid hsl(var(--border));
}

.github-markdown :deep(th) {
    font-weight: 600;
    background: hsl(var(--muted));
}

.github-markdown :deep(details) {
    padding: 0.5rem 0.75rem;
    border: 1px solid hsl(var(--border));
    border-radius: 0.375rem;
}

.github-markdown :deep(summary) {
    cursor: pointer;
    font-weight: 500;
}

.github-markdown :deep(details[open] > summary) {
    margin-bottom: 0.5rem;
}

/* "Suggested change" blocks in review comments. */
.github-markdown :deep(.js-suggested-changes-blob),
.github-markdown :deep(.blob-wrapper) {
    overflow-x: auto;
    border: 1px solid hsl(var(--border));
    border-radius: 0.375rem;
}

.github-markdown :deep(.blob-wrapper table) {
    display: table;
    width: 100%;
    margin: 0;
    border: 0;
}

.github-markdown :deep(.blob-wrapper td) {
    border: 0;
    padding: 0 0.75rem;
    font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace;
    font-size: 0.75rem;
    white-space: pre;
}

.github-markdown :deep(.blob-num) {
    width: 1%;
    min-width: 2.5rem;
    color: hsl(var(--muted-foreground));
    text-align: right;
    user-select: none;
}

.github-markdown :deep(.blob-num::before) {
    content: attr(data-line-number);
}

.github-markdown :deep(.blob-code-deletion),
.github-markdown :deep(.blob-num-deletion) {
    background: rgb(239 68 68 / 0.12);
}

.github-markdown :deep(.blob-code-addition),
.github-markdown :deep(.blob-num-addition) {
    background: rgb(16 185 129 / 0.12);
}

.github-markdown :deep(.blob-code-marker-deletion::before) {
    content: '- ';
}

.github-markdown :deep(.blob-code-marker-addition::before) {
    content: '+ ';
}
</style>
