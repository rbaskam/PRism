<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { Bell, Bot, FileCode2, Github, Info, Palette, ShieldCheck } from 'lucide-vue-next';

const sidebarNavItems: NavItem[] = [
    { title: 'GitHub', href: route('settings.github'), icon: Github },
    { title: 'Claude', href: route('settings.claude'), icon: Bot },
    { title: 'PR review', href: route('settings.review'), icon: FileCode2 },
    { title: 'Notifications', href: route('settings.notifications'), icon: Bell },
    { title: 'Security', href: route('settings.security'), icon: ShieldCheck },
    { title: 'Appearance', href: route('appearance'), icon: Palette },
    { title: 'About', href: route('settings.about'), icon: Info },
];

const page = usePage();

function isActive(item: NavItem): boolean {
    return page.url.startsWith(new URL(item.href, window.location.origin).pathname);
}
</script>

<template>
    <div class="px-4 py-6">
        <Heading title="Settings" description="Connections, the AI review prompt and what the app may write to GitHub" />

        <div class="flex flex-col space-y-8 md:space-y-0 lg:flex-row lg:space-x-12 lg:space-y-0">
            <aside class="w-full max-w-xl lg:w-48">
                <nav class="flex flex-col space-x-0 space-y-1">
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="item.href"
                        variant="ghost"
                        :class="['w-full justify-start', { 'bg-muted': isActive(item) }]"
                        as-child
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" />
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="my-6 md:hidden" />

            <div class="flex-1 md:max-w-3xl">
                <section class="max-w-3xl space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
