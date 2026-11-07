<script setup lang="ts">
/**
 * Marks a pull request whose latest AI review found something new since the user last opened it. It goes away once
 * they open the pull request. The dot is violet like everything else AI, and screen readers hear it as text.
 */
</script>

<template>
    <span
        data-unseen-findings
        class="inline-block size-2 shrink-0 rounded-full bg-violet-500 ring-2 ring-violet-500/20 dark:bg-violet-400 dark:ring-violet-400/25"
        title="New AI findings since you last opened this pull request"
    >
        <span class="sr-only">New AI findings</span>
    </span>
</template>
