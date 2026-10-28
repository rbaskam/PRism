<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { severityStyles } from '@/lib/pull-requests';
import type { FindingSeverity } from '@/types';
import { computed } from 'vue';

/** A finding's severity, drawn the same way everywhere. Critical is solid red so it stands apart from high. */
const props = defineProps<{
    severity: FindingSeverity;
}>();

const style = computed(() => severityStyles[props.severity]);
</script>

<template>
    <Badge :variant="style.badge" class="mt-px shrink-0 px-1.5 text-[10px] uppercase tracking-wide">
        {{ style.label }}
    </Badge>
</template>
