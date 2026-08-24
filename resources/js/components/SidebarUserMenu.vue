<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ChevronsUpDown } from '@lucide/vue';
import { computed } from 'vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import UserAvatar from '@/components/UserAvatar.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useCollapsed } from '@/composables/useShell';

const page = usePage();
const collapsed = useCollapsed();

const user = computed(() => page.props.auth.user);
</script>

<!--
  The account sits at the foot of the sidebar rather than in the topbar corner: the workspace it
  belongs to sits at the head of the same column, so who you are and where you are read as one
  thing. The menu opens upwards for the reason every footer menu does — downwards there is no
  screen left.
-->
<template>
    <DropdownMenu v-if="user">
        <DropdownMenuTrigger
            class="flex h-11 w-full items-center gap-2 rounded-md px-2 text-left transition-colors hover:bg-chrome-accent focus-visible:ring-2 focus-visible:ring-chrome-primary focus-visible:outline-none data-[state=open]:bg-chrome-accent"
            :class="collapsed && 'justify-center px-0'"
            :aria-label="`Account menu for ${user.name}`"
        >
            <UserAvatar :user="user" />

            <span v-if="!collapsed" class="grid flex-1 leading-tight">
                <span class="truncate text-[13px] font-semibold text-chrome-foreground">{{ user.name }}</span>
                <span class="truncate text-[11px] text-chrome-muted-foreground">{{ user.email }}</span>
            </span>

            <ChevronsUpDown v-if="!collapsed" class="size-3.5 shrink-0 text-chrome-muted-foreground" />
        </DropdownMenuTrigger>

        <DropdownMenuContent class="min-w-60" align="start" side="top" :side-offset="8">
            <UserMenuContent :user="user" />
        </DropdownMenuContent>
    </DropdownMenu>
</template>
