<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Check, ChevronsUpDown, List, Plus } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useCollapsed } from '@/composables/useShell';
import type { WorkspaceSummary } from '@/modules/workspace/types';
// Wayfinder exports `switchMethod`: `switch` is a reserved word in JavaScript.
import { create, index, switchMethod } from '@/routes/workspaces';

const page = usePage();
const collapsed = useCollapsed();

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
    <DropdownMenu v-if="current">
        <DropdownMenuTrigger
            class="flex h-11 w-full items-center gap-2 rounded-md px-2 text-left transition-colors hover:bg-chrome-accent focus-visible:ring-2 focus-visible:ring-chrome-primary focus-visible:outline-none data-[state=open]:bg-chrome-accent"
            :class="collapsed && 'justify-center px-0'"
            :aria-label="`Current workspace: ${current.name}. Switch workspace`"
        >
            <span
                class="flex size-7 shrink-0 items-center justify-center rounded-md bg-chrome-primary text-[13px] font-bold text-chrome-primary-foreground"
            >
                {{ current.name.charAt(0).toUpperCase() }}
            </span>

            <span v-if="!collapsed" class="grid flex-1 leading-tight">
                <span class="truncate text-[13px] font-semibold text-chrome-foreground">{{ current.name }}</span>
                <span class="truncate text-[11px] text-chrome-muted-foreground">{{ current.slug }}</span>
            </span>

            <ChevronsUpDown v-if="!collapsed" class="size-3.5 shrink-0 text-chrome-muted-foreground" />
        </DropdownMenuTrigger>

        <DropdownMenuContent class="min-w-60" align="start" side="bottom">
            <DropdownMenuLabel class="text-xs text-muted-foreground">Workspaces</DropdownMenuLabel>

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
                <Link :href="index()" component="workspaces/Index">
                    <List class="size-4" />
                    <span>All workspaces and invitations</span>
                </Link>
            </DropdownMenuItem>

            <DropdownMenuItem as-child class="gap-2">
                <Link :href="create()">
                    <Plus class="size-4" />
                    <span>New workspace</span>
                </Link>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>

    <Link
        v-else
        :href="index()"
        class="flex h-11 items-center gap-2 rounded-md px-2 text-[13px] font-medium text-chrome-foreground transition-colors hover:bg-chrome-accent focus-visible:ring-2 focus-visible:ring-chrome-primary focus-visible:outline-none"
        :class="collapsed && 'justify-center px-0'"
    >
        <Plus class="size-4 shrink-0" />
        <span v-if="!collapsed" class="truncate">Find a workspace</span>
    </Link>
</template>
