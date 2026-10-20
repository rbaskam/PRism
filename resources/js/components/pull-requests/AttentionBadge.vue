<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { attentionStyles } from '@/lib/pull-requests';
import type { ReviewAttention } from '@/types';
import { computed } from 'vue';

const props = defineProps<{
    attention: ReviewAttention;
}>();

const style = computed(() => attentionStyles[props.attention]);
</script>

<template>
    <Badge :variant="style.badge">
        <span class="size-1.5 shrink-0 rounded-full" :class="style.dot" aria-hidden="true" />
        {{ style.label }}
    </Badge>
</template>
