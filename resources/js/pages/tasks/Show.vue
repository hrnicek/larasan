<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { useRealtime } from '@/composables/useRealtime';
import TaskDetailBody from '@/modules/task/components/TaskDetailBody.vue';
import type { TaskAssignee, TaskDetail, TaskFeed } from '@/modules/task/types';

/**
 * A task's own page: the same component the panel renders, with nothing to close to.
 */
const props = defineProps<TaskDetail & {
    members: TaskAssignee[];
    priorities: string[];
    activity?: TaskFeed;
}>();

const page = usePage();

/*
 * Where a task appears decides who hears about it, so the channels are its placements — and
 * the workspace when it has none, which is where a loose task is announced. A guess cannot
 * leak anything: `routes/channels.php` refuses a channel this person may not join.
 */
useRealtime({
    channels: () => {
        if (props.placements.length > 0) {
            return props.placements.map((placement) => `project.${placement.project.id}`);
        }

        return page.props.workspace === null ? [] : [`workspace.${page.props.workspace.id}`];
    },
});

const detail = (): TaskDetail => ({
    task: props.task,
    placements: props.placements,
    availableProjects: props.availableProjects,
    followers: props.followers,
    following: props.following,
    subtasks: props.subtasks,
    customFields: props.customFields,
    tags: props.tags,
    availableTags: props.availableTags,
    attachments: props.attachments,
    can: props.can,
});
</script>

<template>
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-4 px-4 py-8 md:px-6">
        <Head :title="task.title" />

        <!-- The task is what this page is. The panel renders the title as a field, which is
             a control rather than a heading. -->
        <h1 class="sr-only">{{ task.title }}</h1>

        <TaskDetailBody
            :detail="detail()"
            :members="members"
            :priorities="priorities"
            :activity="activity"
        />
    </div>
</template>
