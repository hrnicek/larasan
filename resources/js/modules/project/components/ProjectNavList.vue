<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Hash, Plus } from '@lucide/vue';
import { computed } from 'vue';
import {
    SidebarGroup,
    SidebarGroupAction,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { accentTextClass } from '@/lib/accentColor';
import type { ProjectSummary } from '@/modules/project/types';
import { create, edit, index } from '@/routes/projects';

const page = usePage();
const { isCurrentUrl } = useCurrentUrl();

const projects = computed<ProjectSummary[]>(() => page.props.projects);
const canCreate = computed<boolean>(() => page.props.auth.capabilities.includes('project.create'));
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>Projects</SidebarGroupLabel>

        <SidebarGroupAction v-if="canCreate" as-child title="New project">
            <Link :href="create().url">
                <Plus />
                <span class="sr-only">New project</span>
            </Link>
        </SidebarGroupAction>

        <SidebarMenu v-if="projects.length">
            <SidebarMenuItem v-for="project in projects" :key="project.id">
                <SidebarMenuButton as-child :is-active="isCurrentUrl(edit(project.id).url)" :tooltip="project.name">
                    <Link :href="edit(project.id).url">
                        <Hash :class="accentTextClass(project.color)" />
                        <span class="truncate">{{ project.name }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>

            <SidebarMenuItem>
                <SidebarMenuButton as-child tooltip="All projects">
                    <Link :href="index().url">
                        <span class="text-muted-foreground text-xs">All projects</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>

        <SidebarMenu v-else>
            <SidebarMenuItem>
                <SidebarMenuButton v-if="canCreate" as-child tooltip="Create your first project">
                    <Link :href="create().url">
                        <Plus />
                        <span>Create your first project</span>
                    </Link>
                </SidebarMenuButton>
                <span v-else class="text-muted-foreground px-2 py-1.5 text-xs">No projects yet</span>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
