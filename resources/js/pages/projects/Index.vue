<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight, FolderOpen, Lock, Plus, Settings } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import ProjectTile from '@/modules/project/components/ProjectTile.vue';
import type { ProjectSummary } from '@/modules/project/types';
import { create, edit, show } from '@/routes/projects';

defineProps<{
    /** Uncapped, unlike the shared sidebar `projects` prop. */
    allProjects: ProjectSummary[];
    can: { create: boolean };
}>();
</script>

<template>
    <div class="flex flex-col">
        <Head title="Projects" />

        <PageHeader title="Projects" description="Everything you can reach in this workspace">
            <template v-if="can.create" #actions>
                <Link
                    :href="create().url"
                    class="inline-flex h-8 items-center gap-1.5 rounded-md bg-primary px-2.5 text-[13px] font-semibold text-primary-foreground transition-colors hover:bg-primary-hover focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                >
                    <Plus class="size-4" />
                    New project
                </Link>
            </template>
        </PageHeader>

        <ul v-if="allProjects.length" class="flex flex-col divide-y divide-border border-b border-border">
            <li
                v-for="project in allProjects"
                :key="project.id"
                class="group/row flex items-center gap-3 px-4 transition-colors hover:bg-accent/40 md:px-6"
            >
                <Link
                    :href="show(project.id).url"
                    component="projects/Show"
                    prefetch="click"
                    class="flex min-h-11 min-w-0 flex-1 items-center gap-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                >
                    <ProjectTile :name="project.name" :color="project.color" :icon="project.icon" />

                    <span class="truncate font-medium">{{ project.name }}</span>

                    <span
                        v-if="project.visibility === 'private'"
                        class="inline-flex shrink-0 items-center gap-1 rounded bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground"
                    >
                        <Lock class="size-3" aria-hidden="true" />
                        Private
                    </span>
                </Link>

                <Link
                    :href="edit(project.id).url"
                    component="projects/Settings"
                    prefetch="click"
                    class="inline-flex size-11 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none md:size-8 md:opacity-0 md:group-hover/row:opacity-100"
                    :aria-label="`Settings for ${project.name}`"
                >
                    <Settings class="size-4" />
                </Link>

                <ChevronRight class="size-4 shrink-0 text-muted-foreground/60" aria-hidden="true" />
            </li>
        </ul>

        <EmptyState
            v-else
            class="mx-4 mt-6 md:mx-6"
            :icon="FolderOpen"
            title="No projects yet"
            :description="
                can.create
                    ? 'Make the first one — a project is where sections, tasks and people meet.'
                    : 'Nothing has been shared with you in this workspace yet.'
            "
        >
            <template v-if="can.create" #action>
                <Link
                    :href="create().url"
                    class="inline-flex h-9 items-center gap-1.5 rounded-md bg-primary px-3 text-[13px] font-semibold text-primary-foreground transition-colors hover:bg-primary-hover focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                >
                    <Plus class="size-4" />
                    New project
                </Link>
            </template>
        </EmptyState>
    </div>
</template>
