<script setup lang="ts">
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { getInitials } from '@/composables/useInitials';
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{
    login: string | null;
    url: string | null;
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <Avatar :class="cn('size-6 text-[10px]', props.class)">
        <AvatarImage v-if="url" :src="url" :alt="login ?? ''" />
        <AvatarFallback class="font-medium">{{ getInitials(login ?? '?') }}</AvatarFallback>
    </Avatar>
</template>
