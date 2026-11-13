<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import AccentPicker from '@/components/AccentPicker.vue';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { useMemeReactions } from '@/composables/useMemeReactions';
import { memeMoments } from '@/lib/memes';
import { type BreadcrumbItem } from '@/types';

import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Appearance settings',
        href: '/settings/appearance',
    },
];

const { enabled: memeReactionsEnabled, celebrate } = useMemeReactions();

function previewMeme(): void {
    celebrate(memeMoments[Math.floor(Math.random() * memeMoments.length)]);
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Appearance settings" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall title="Appearance settings" description="Choose how the app looks on this computer." />

                <div class="space-y-3">
                    <p class="text-sm font-medium">Mode</p>
                    <AppearanceTabs />
                </div>

                <div class="space-y-3">
                    <div class="space-y-1">
                        <p class="text-sm font-medium">Accent colour</p>
                        <p class="text-sm text-muted-foreground">Used for buttons, switches, focus rings and the selected item in the sidebar.</p>
                    </div>
                    <AccentPicker />
                </div>

                <div class="flex items-start justify-between gap-4 rounded-lg border p-4">
                    <div class="min-w-0 space-y-1">
                        <Label for="meme-reactions" class="cursor-pointer">Meme reactions</Label>
                        <p class="text-sm text-muted-foreground">
                            A meme and a funny line when you approve, request changes, comment or start Fix with Claude. The GIFs load from Giphy.
                        </p>
                        <Button v-if="memeReactionsEnabled" type="button" variant="link" class="h-auto p-0" @click="previewMeme">
                            Show me one
                        </Button>
                    </div>
                    <Switch id="meme-reactions" v-model:checked="memeReactionsEnabled" class="mt-0.5" />
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
