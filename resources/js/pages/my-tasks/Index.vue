<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import TaskRow from '@/modules/task/components/TaskRow.vue';
import type { MyTaskRow } from '@/modules/task/types';

/**
 * What this person is responsible for, in the workspace they are standing in.
 *
 * The rows are the list view's rows, not a second set that looks like them: two components
 * drawing a task is two places for a task to be drawn wrongly. What My Tasks adds is the
 * projects a task belongs to, which is where multi-project membership becomes visible
 * (`docs/ui/inbox.md`).
 */
defineProps<{
    tasks: MyTaskRow[];
    tabs: string[];
    meta: { tab: string; page: number; perPage: number; total: number; hasMore: boolean };
    can: { updateTask: boolean };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'My Tasks', href: '/my-tasks' }] },
});

const labels: Record<string, string> = {
    today: 'Today',
    upcoming: 'Upcoming',
    overdue: 'Overdue',
    completed: 'Completed',
};

/** The empty state says what is true of *this* tab. An empty Overdue is a good outcome. */
const emptyMessages: Record<string, string> = {
    today: 'Nothing due today.',
    upcoming: 'Nothing coming up.',
    overdue: 'Nothing overdue.',
    completed: 'Nothing finished yet.',
};

const show = (tab: string): void => {
    // The tab lives in the URL so a link carries the view and a refresh lands back on it.
    router.get('/my-tasks', { tab }, { preserveScroll: true, preserveState: true });
};

const open = (taskId: string): void => {
    router.get(`/tasks/${taskId}`);
};
</script>

<template>
    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <Head title="My Tasks" />

        <nav class="flex gap-1 overflow-x-auto" aria-label="My Tasks views">
            <button
                v-for="tab in tabs"
                :key="tab"
                type="button"
                class="rounded px-2 py-1 text-sm"
                :class="tab === meta.tab ? 'bg-muted font-medium' : 'text-muted-foreground'"
                :aria-current="tab === meta.tab ? 'page' : undefined"
                @click="show(tab)"
            >
                {{ labels[tab] ?? tab }}
            </button>
        </nav>

        <ul v-if="tasks.length" class="flex flex-col gap-1">
            <li v-for="task in tasks" :key="task.id" class="flex flex-col">
                <TaskRow
                    :task="task"
                    :members="[]"
                    :priorities="[]"
                    :editable="can.updateTask"
                    @open="open"
                />

                <p v-if="task.projects.length" class="pl-7 text-xs text-muted-foreground">
                    <span v-for="project in task.projects" :key="project.id" class="mr-2">{{ project.name }}</span>
                </p>
            </li>
        </ul>

        <p v-else class="text-sm text-muted-foreground">{{ emptyMessages[meta.tab] ?? 'Nothing here.' }}</p>
    </div>
</template>
