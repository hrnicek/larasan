<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import MyTasksController from '@/actions/App/Http/Controllers/Task/MyTasksController';
import TaskController from '@/actions/App/Http/Controllers/Task/TaskController';
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
const props = defineProps<{
    tasks: MyTaskRow[];
    tabs: string[];
    meta: { tab: string; page: number; perPage: number; total: number; hasMore: boolean };
    can: { updateTask: boolean };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'My Tasks', href: MyTasksController.index.url() }] },
});

const labels: Record<string, string> = {
    today: 'Today',
    upcoming: 'Upcoming',
    overdue: 'Overdue',
    completed: 'Completed',
};

/**
 * Per tab, because "nothing here" means something different in each. An empty Overdue is a good
 * outcome and reads like one.
 */
const emptyMessages: Record<string, string> = {
    today: 'Nothing due today.',
    upcoming: 'Nothing coming up.',
    overdue: 'Nothing overdue.',
    completed: 'Nothing finished yet.',
};

/*
 * The rows the screen is showing, which is the server's page plus any pages appended since.
 * "Load more" adds to a list; replacing it would make the button scroll the reader backwards.
 */
const rows = ref<MyTaskRow[]>([...props.tasks]);
const loading = ref(false);

watch(() => props.tasks, (tasks) => {
    rows.value = props.meta.page === 1 ? [...tasks] : [...rows.value, ...tasks];
});

/** Only the list region: the tabs and the shell are already correct. */
const reloadList = (tab: string, page: number): void => {
    loading.value = true;

    router.get(
        MyTasksController.index.url({ query: { tab, page } }),
        {},
        {
            only: ['tasks', 'meta'],
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                loading.value = false;
            },
        },
    );
};

const show = (tab: string): void => {
    if (tab === props.meta.tab) {
        return;
    }

    // A new tab is a new list, so the appended pages go with it.
    rows.value = [];
    reloadList(tab, 1);
};

const loadMore = (): void => {
    if (!props.meta.hasMore || loading.value) {
        return;
    }

    reloadList(props.meta.tab, props.meta.page + 1);
};

const open = (taskId: string): void => {
    router.get(TaskController.show.url(taskId));
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
                class="rounded px-2 py-1 text-sm whitespace-nowrap"
                :class="tab === meta.tab ? 'bg-muted font-medium' : 'text-muted-foreground'"
                :aria-current="tab === meta.tab ? 'page' : undefined"
                @click="show(tab)"
            >
                {{ labels[tab] ?? tab }}
            </button>
        </nav>

        <ul v-if="rows.length" class="flex flex-col gap-1">
            <li v-for="task in rows" :key="task.id" class="flex flex-col">
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

        <p v-else-if="!loading" class="text-sm text-muted-foreground">
            {{ emptyMessages[meta.tab] ?? 'Nothing here.' }}
        </p>

        <button
            v-if="meta.hasMore"
            type="button"
            class="self-start rounded border border-input px-2 py-1 text-xs"
            :disabled="loading"
            @click="loadMore"
        >
            {{ loading ? 'Loading…' : 'Load more' }}
        </button>
    </div>
</template>
