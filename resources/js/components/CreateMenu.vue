<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Building2, CheckSquare, ChevronDown, FolderPlus, Plus, UserPlus } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { create as createProject } from '@/routes/projects';
import { create as createTask } from '@/routes/tasks';
import { create as createWorkspace, members } from '@/routes/workspaces';

const page = usePage();

/*
 * The server sends the capabilities; this only renders them. An entry the actor cannot use is
 * absent rather than disabled — a menu is a list of what you can do.
 */
const capabilities = computed<string[]>(() => page.props.auth.capabilities);
const canCreateTask = computed(() => capabilities.value.includes('task.create'));
const canCreateProject = computed(() => capabilities.value.includes('project.create'));
const canInvite = computed(() => capabilities.value.includes('workspace.members.manage'));
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger
            class="inline-flex h-8 items-center gap-1.5 rounded-md bg-chrome-primary pr-2 pl-2.5 text-[13px] font-semibold text-chrome-primary-foreground transition-colors hover:bg-chrome-primary/90 focus-visible:ring-2 focus-visible:ring-chrome-primary focus-visible:ring-offset-2 focus-visible:ring-offset-chrome focus-visible:outline-none"
        >
            <Plus class="size-4" />
            <span>Create</span>
            <ChevronDown class="size-3.5 opacity-70" />
        </DropdownMenuTrigger>

        <DropdownMenuContent align="start" class="w-52">
            <DropdownMenuItem v-if="canCreateTask" as-child>
                <Link :href="createTask()" class="block w-full cursor-pointer">
                    <CheckSquare class="mr-2 size-4 text-muted-foreground" />
                    Task
                </Link>
            </DropdownMenuItem>

            <DropdownMenuItem v-if="canCreateProject" as-child>
                <Link :href="createProject()" class="block w-full cursor-pointer">
                    <FolderPlus class="mr-2 size-4 text-muted-foreground" />
                    Project
                </Link>
            </DropdownMenuItem>

            <DropdownMenuItem as-child>
                <Link :href="createWorkspace()" class="block w-full cursor-pointer">
                    <Building2 class="mr-2 size-4 text-muted-foreground" />
                    Workspace
                </Link>
            </DropdownMenuItem>

            <template v-if="canInvite">
                <DropdownMenuSeparator />
                <DropdownMenuItem as-child>
                    <Link :href="members()" class="block w-full cursor-pointer">
                        <UserPlus class="mr-2 size-4 text-muted-foreground" />
                        Invite
                    </Link>
                </DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
