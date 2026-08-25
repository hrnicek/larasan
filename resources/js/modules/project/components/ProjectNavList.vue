<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed } from 'vue';
import ChromeNavItem from '@/components/ChromeNavItem.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useCollapsed } from '@/composables/useShell';
import ProjectContextMenu from '@/modules/project/components/ProjectContextMenu.vue';
import ProjectTile from '@/modules/project/components/ProjectTile.vue';
import type { SidebarProject } from '@/modules/project/types';
import { create, show } from '@/routes/projects';

const page = usePage();
const { isCurrentUrl } = useCurrentUrl();
const collapsed = useCollapsed();

const projects = computed<SidebarProject[]>(() => page.props.projects);
const canCreate = computed<boolean>(() => page.props.auth.capabilities.includes('project.create'));
</script>

<template>
    <div class="space-y-1">
        <div v-if="!collapsed" class="flex h-7 items-center gap-1 pr-1 pl-2">
            <h2 class="text-[11px] font-semibold tracking-wide text-chrome-muted-foreground uppercase">Projects</h2>

            <Link
                v-if="canCreate"
                :href="create()"
                class="ml-auto inline-flex size-6 items-center justify-center rounded-md text-chrome-muted-foreground transition-colors hover:bg-chrome-accent hover:text-chrome-foreground focus-visible:ring-2 focus-visible:ring-chrome-primary focus-visible:outline-none"
            >
                <Plus class="size-4" />
                <span class="sr-only">New project</span>
            </Link>
        </div>

        <template v-if="projects.length">
            <!--
                A project in the sidebar opens the project, not its settings. A right click on the
                row opens what else can be done to it, which is where somebody reaches for those
                actions — the alternative is navigating away from what they were looking at first.
            -->
            <ProjectContextMenu v-for="project in projects" :key="project.id" :project="project">
                <ChromeNavItem
                    :href="show(project.id).url"
                    :label="project.name"
                    :active="isCurrentUrl(show(project.id).url)"
                >
                    <template #icon>
                        <!--
                            The same tile collapsed and expanded. A bare glyph beside a name says
                            what kind of project it is rather than which one: the colour is what
                            tells two boards apart at a glance, and the tile carries the project's
                            first letter when it has no icon at all.
                        -->
                        <ProjectTile
                            :name="project.name"
                            :color="project.color"
                            :icon="project.icon"
                            size="sm"
                            surface="chrome"
                        />
                    </template>
                </ChromeNavItem>
            </ProjectContextMenu>
        </template>

        <!--
            The empty state names the next action rather than the absence. Somebody who cannot
            create one is told why the list is empty instead of being offered a control that
            would refuse them.
        -->
        <template v-else-if="!collapsed">
            <Link
                v-if="canCreate"
                :href="create()"
                class="flex h-9 items-center gap-2.5 rounded-md px-2 text-[13px] font-medium text-chrome-muted-foreground transition-colors hover:bg-chrome-accent hover:text-chrome-foreground focus-visible:ring-2 focus-visible:ring-chrome-primary focus-visible:outline-none"
            >
                <Plus class="size-4 shrink-0" />
                Create your first project
            </Link>
            <p v-else class="px-2 py-1.5 text-xs text-chrome-muted-foreground">
                No projects here yet. Ask an admin to add you to one.
            </p>
        </template>
    </div>
</template>
