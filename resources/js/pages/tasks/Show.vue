<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { useRealtime } from '@/composables/useRealtime';
import TaskDetailBody from '@/modules/task/components/TaskDetailBody.vue';
import TaskDetailToolbar from '@/modules/task/components/TaskDetailToolbar.vue';
import type { TaskAssignee, TaskDetail, TaskFeed } from '@/modules/task/types';
import { index as myTasks } from '@/routes/my-tasks';
import { show as showProject } from '@/routes/projects';
import { show as showTask } from '@/routes/tasks';

const props = defineProps<
    TaskDetail & {
        members: TaskAssignee[];
        priorities: string[];
        activity?: TaskFeed;
    }
>();

const page = usePage();

// A task without placements is broadcast on the workspace channel; routes/channels.php authorizes each subscription.
useRealtime({
    channels: () => {
        if (props.placements.length > 0) {
            return props.placements.map(
                (placement) => `project.${placement.project.id}`,
            );
        }

        return page.props.workspace === null
            ? []
            : [`workspace.${page.props.workspace.id}`];
    },
});

const back = (): { url: string; label: string; component: string } => {
    const placement = props.placements[0];

    return placement === undefined
        ? { url: myTasks().url, label: 'My Tasks', component: 'my-tasks/Index' }
        : {
              url: showProject(placement.project.id).url,
              label: placement.project.name,
              component: 'projects/Show',
          };
};

const detail = (): TaskDetail => ({
    task: props.task,
    placements: props.placements,
    availableProjects: props.availableProjects,
    collaborators: props.collaborators,
    collaborating: props.collaborating,
    followers: props.followers,
    following: props.following,
    starred: props.starred,
    subtasks: props.subtasks,
    customFields: props.customFields,
    tags: props.tags,
    availableTags: props.availableTags,
    attachments: props.attachments,
    can: props.can,
});

const leave = (): void =>
    router.visit(back().url, { component: back().component });

const openTask = (taskId: string): void =>
    router.visit(showTask(taskId).url, { component: 'tasks/Show' });
</script>

<template>
    <div class="mx-auto flex w-full max-w-3xl flex-col">
        <Head :title="task.title" />

        <!-- The visible title is an editable field, not a heading. -->
        <h1 class="sr-only">{{ task.title }}</h1>

        <div class="px-4 pt-6 pb-3 md:px-6">
            <Link
                :href="back().url"
                :component="back().component"
                prefetch="click"
                class="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
            >
                <ArrowLeft class="size-4" aria-hidden="true" />
                {{ back().label }}
            </Link>
        </div>

        <TaskDetailToolbar
            :detail="detail()"
            variant="page"
            class="sticky top-0 z-10"
            @deleted="leave"
        />

        <TaskDetailBody
            :detail="detail()"
            :members="members"
            :priorities="priorities"
            :activity="activity"
            @open="openTask"
        />
    </div>
</template>
