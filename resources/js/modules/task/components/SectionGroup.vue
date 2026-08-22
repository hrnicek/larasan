<script setup lang="ts">
import InlineTaskCreate from '@/modules/task/components/InlineTaskCreate.vue';
import TaskListSkeleton from '@/modules/task/components/TaskListSkeleton.vue';
import TaskRow from '@/modules/task/components/TaskRow.vue';
import type { TaskAssignee, TaskSectionGroup } from '@/modules/task/types';

/**
 * A column of the list. The count comes from the server alongside the rows it counted, so
 * the header cannot disagree with what is drawn.
 */
const props = defineProps<{
    section: TaskSectionGroup;
    members: TaskAssignee[];
    priorities: string[];
    editable: boolean;
    creatable: boolean;
    collapsed: boolean;
    loading: boolean;
    projectId: string;
}>();

const emit = defineEmits<{ toggle: [sectionId: string | null] }>();

const toggle = () => emit('toggle', props.section.id);
</script>

<template>
    <section class="rounded-lg border" data-task-section>
        <button
            type="button"
            class="flex w-full items-center justify-between px-4 py-2 text-sm font-medium"
            :aria-expanded="!collapsed"
            @click="toggle"
        >
            <span>{{ section.name ?? 'No section' }}</span>
            <span class="text-xs text-muted-foreground">{{ section.count }}</span>
        </button>

        <TaskListSkeleton v-if="!collapsed && loading" :rows="Math.max(section.count, 1)" />

        <div v-else-if="!collapsed" class="divide-y border-t">
            <TaskRow
                v-for="task in section.tasks"
                :key="task.placementId"
                :task="task"
                :members="members"
                :priorities="priorities"
                :editable="editable"
            />
            <p v-if="section.tasks.length === 0" class="px-4 py-3 text-sm text-muted-foreground">
                No tasks
            </p>

            <InlineTaskCreate
                v-if="creatable"
                :project-id="projectId"
                :section-id="section.id"
            />
        </div>
    </section>
</template>
