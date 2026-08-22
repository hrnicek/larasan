<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import TaskDetailPanel from '@/modules/task/components/TaskDetailPanel.vue';
import type { TaskAssignee, TaskDetail } from '@/modules/task/types';

/**
 * A task's own page: the same component the panel renders, with nothing to close to.
 */
const props = defineProps<TaskDetail & { members: TaskAssignee[]; priorities: string[] }>();

const detail = (): TaskDetail => ({
    task: props.task,
    placements: props.placements,
    subtasks: props.subtasks,
    can: props.can,
});
</script>

<template>
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-4">
        <Head :title="task.title" />

        <TaskDetailPanel
            :detail="detail()"
            :dismissible="false"
            :members="members"
            :priorities="priorities"
        />
    </div>
</template>
