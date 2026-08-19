<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Check, ChevronsUpDown, Plus } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import type { WorkspaceSummary } from '@/modules/workspace/types';
// Wayfinder exports `switchMethod`: `switch` is a reserved word in JavaScript.
import { create, switchMethod } from '@/routes/workspaces';

const page = usePage();

const current = computed<WorkspaceSummary | null>(() => page.props.workspace);
const workspaces = computed<WorkspaceSummary[]>(() => page.props.workspaces);

function switchTo(workspace: WorkspaceSummary): void {
    if (workspace.id === current.value?.id) {
        return;
    }

    router.post(switchMethod(workspace.slug).url, {}, { preserveScroll: true });
}
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem>
            <DropdownMenu v-if="current">
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton
                        size="lg"
                        class="data-[state=open]:bg-sidebar-accent"
                        :aria-label="`Current workspace: ${current.name}. Switch workspace`"
                    >
                        <div class="bg-sidebar-primary text-sidebar-primary-foreground flex aspect-square size-8 items-center justify-center rounded-lg text-sm font-semibold">
                            {{ current.name.charAt(0).toUpperCase() }}
                        </div>
                        <div class="grid flex-1 text-left text-sm leading-tight">
                            <span class="truncate font-medium">{{ current.name }}</span>
                            <span class="text-muted-foreground truncate text-xs">{{ current.slug }}</span>
                        </div>
                        <ChevronsUpDown class="ml-auto size-4" />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>

                <DropdownMenuContent class="w-(--reka-dropdown-menu-trigger-width) min-w-56" align="start" side="bottom">
                    <DropdownMenuLabel class="text-muted-foreground text-xs">Workspaces</DropdownMenuLabel>

                    <DropdownMenuItem
                        v-for="workspace in workspaces"
                        :key="workspace.id"
                        class="gap-2"
                        @select="switchTo(workspace)"
                    >
                        <span class="flex-1 truncate">{{ workspace.name }}</span>
                        <Check v-if="workspace.id === current.id" class="size-4" />
                    </DropdownMenuItem>

                    <DropdownMenuSeparator />

                    <DropdownMenuItem as-child class="gap-2">
                        <Link :href="create()">
                            <Plus class="size-4" />
                            <span>New workspace</span>
                        </Link>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <SidebarMenuButton v-else size="lg" as-child>
                <Link :href="create()" class="gap-2">
                    <Plus class="size-4" />
                    <span class="truncate font-medium">Create a workspace</span>
                </Link>
            </SidebarMenuButton>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
