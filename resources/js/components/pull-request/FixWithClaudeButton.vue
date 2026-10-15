<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import type { PullRequestPermissions } from '@/types';
import { Link } from '@inertiajs/vue3';
import { Loader2, Wand2 } from 'lucide-vue-next';
import { computed } from 'vue';
import { codeChangeBlockers } from './utils';

/**
 * Opens "Fix with Claude". When it can't be used, the tooltip says which setting is missing and links to it.
 */
const props = withDefaults(
    defineProps<{
        permissions: PullRequestPermissions;
        /** Claude is already changing or pushing code for this pull request. */
        isWorking: boolean;
        size?: 'default' | 'sm';
        variant?: 'default' | 'outline';
    }>(),
    {
        size: 'sm',
        variant: 'outline',
    },
);

const emit = defineEmits<{
    click: [];
}>();

const blockers = computed(() => (props.permissions.can_change_code ? [] : codeChangeBlockers(props.permissions)));
</script>

<template>
    <div class="contents">
        <Tooltip v-if="blockers.length > 0">
            <TooltipTrigger as-child>
                <span tabindex="0" class="inline-flex">
                    <Button :size="size" :variant="variant" disabled>
                        <Wand2 />
                        Fix with Claude
                    </Button>
                </span>
            </TooltipTrigger>
            <TooltipContent class="max-w-xs space-y-1 text-xs">
                <p>To let Claude change this pull request's code:</p>
                <ul class="list-disc space-y-0.5 pl-4">
                    <li v-for="blocker in blockers" :key="blocker.href">
                        <Link :href="blocker.href" class="font-medium underline underline-offset-2">{{ blocker.label }}</Link>
                    </li>
                </ul>
            </TooltipContent>
        </Tooltip>

        <Button v-else-if="isWorking" :size="size" :variant="variant" disabled>
            <Loader2 class="animate-spin" />
            Claude is fixing…
        </Button>

        <Button v-else :size="size" :variant="variant" @click="emit('click')">
            <Wand2 />
            Fix with Claude
        </Button>
    </div>
</template>
