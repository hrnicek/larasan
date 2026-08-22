<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useCollapsedSections } from '@/composables/useCollapsedSections';
import ProjectHeader from '@/modules/project/components/ProjectHeader.vue';
import SectionGroup from '@/modules/task/components/SectionGroup.vue';
import type { ProjectList, TaskAssignee } from '@/modules/task/types';

/**
 * The project's own screen. The board arrives in Phase 090; until then the switcher is
 * honest about it rather than rendering a list under the wrong name.
 */
const props = defineProps<{
    project: { id: string; name: string; slug: string; color: string | null; icon: string | null; archived: boolean };
    view: string;
    views: string[];
    list: ProjectList;
    members: TaskAssignee[];
}>();

const editable = () => props.list.can.updateTask;

const { isCollapsed, toggle } = useCollapsedSections(props.project.id);
</script>

<template>
    <div class="flex flex-col space-y-6">
        <Head :title="project.name" />

        <ProjectHeader :project="project" :view="view" :views="views" />

        <p v-if="view === 'board'" class="rounded-lg border border-dashed px-4 py-6 text-sm text-muted-foreground">
            The board view is not built yet. Switch to the list to see this project's tasks.
        </p>

        <div v-else-if="list.sections.length" class="flex flex-col space-y-4">
            <SectionGroup
                v-for="section in list.sections"
                :key="section.id ?? 'ungrouped'"
                :section="section"
                :members="members"
                :editable="editable()"
                :creatable="list.can.createTask"
                :project-id="project.id"
                :collapsed="isCollapsed(section.id)"
                @toggle="toggle"
            />
        </div>

        <p v-else class="text-sm text-muted-foreground">Nothing in this project yet.</p>
    </div>
</template>
