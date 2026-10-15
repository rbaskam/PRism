<script setup lang="ts">
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { useExternalLink } from '@/composables/useExternalLink';
import { getInitials } from '@/composables/useInitials';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronsUpDown, ExternalLink, Settings } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage<SharedData>();
const github = computed(() => page.props.github);
const { openExternal } = useExternalLink();
</script>

<template>
    <SidebarMenu v-if="github">
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton size="lg" class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground">
                        <Avatar class="size-8 overflow-hidden rounded-lg">
                            <AvatarImage v-if="github.avatar_url" :src="github.avatar_url" :alt="github.login" />
                            <AvatarFallback class="rounded-lg">{{ getInitials(github.name ?? github.login) }}</AvatarFallback>
                        </Avatar>
                        <div class="grid flex-1 text-left text-sm leading-tight">
                            <span class="truncate font-medium">{{ github.name ?? github.login }}</span>
                            <span class="truncate text-xs text-muted-foreground">@{{ github.login }}</span>
                        </div>
                        <ChevronsUpDown class="ml-auto size-4" />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent class="w-[--radix-dropdown-menu-trigger-width] min-w-56 rounded-lg" side="bottom" align="end" :side-offset="4">
                    <DropdownMenuLabel class="font-normal text-muted-foreground">Signed in to GitHub as @{{ github.login }}</DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem as-child>
                        <Link class="block w-full" :href="route('settings.github')">
                            <Settings class="mr-2 size-4" />
                            Settings
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem @select="openExternal(`https://github.com/${github.login}`)">
                        <ExternalLink class="mr-2 size-4" />
                        GitHub profile
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
