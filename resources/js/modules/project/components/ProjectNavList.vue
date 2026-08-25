<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed } from 'vue';
import { useCollapsed } from '@/composables/useShell';
import ProjectNavRow from '@/modules/project/components/ProjectNavRow.vue';
import type { SidebarProject } from '@/modules/project/types';
import { create } from '@/routes/projects';

const page = usePage();
const collapsed = useCollapsed();

const projects = computed<SidebarProject[]>(() => page.props.projects);
const canCreate = computed<boolean>(() => page.props.auth.capabilities.includes('project.create'));

/*
 * Two groups from one prop. The server sends the starred rows first — the list is capped, and a
 * project somebody pinned themselves must not be the one the cap cuts off — so this splits what
 * it was given rather than sorting it again.
 */
const starred = computed<SidebarProject[]>(() => projects.value.filter((project) => project.starred));
const rest = computed<SidebarProject[]>(() => projects.value.filter((project) => !project.starred));
</script>

<template>
    <div class="space-y-1">
        <!--
            Starred is a group somebody made themselves, so it is drawn only once they have. An
            empty "Starred" heading is a promise of a feature rather than a place to look.
        -->
        <template v-if="starred.length">
            <h2
                v-if="!collapsed"
                class="flex h-7 items-center pr-1 pl-2 text-[11px] font-semibold tracking-wide text-chrome-muted-foreground uppercase"
            >
                Starred
            </h2>

            <ProjectNavRow v-for="project in starred" :key="project.id" :project="project" />

            <!-- Collapsed there are no headings, so the rule is what says the group ended. -->
            <hr v-if="collapsed" class="my-1 border-chrome-border" />
        </template>

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

        <!--
            A project in the sidebar opens the project, not its settings. A right click on the row
            opens what else can be done to it, which is where somebody reaches for those actions —
            the alternative is navigating away from what they were looking at first.
        -->
        <ProjectNavRow v-for="project in rest" :key="project.id" :project="project" />

        <!--
            The empty state names the next action rather than the absence. Somebody who cannot
            create one is told why the list is empty instead of being offered a control that
            would refuse them. It answers for the whole list, not for this group: somebody whose
            only project is starred has projects.
        -->
        <template v-if="!projects.length && !collapsed">
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
