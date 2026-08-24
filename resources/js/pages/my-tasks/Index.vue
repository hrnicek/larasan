<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { CheckCheck, Plus } from '@lucide/vue';
import { ref, watch } from 'vue';
import MyTasksController from '@/actions/App/Http/Controllers/Task/MyTasksController';
import EmptyState from '@/components/EmptyState.vue';
import TaskDetailPanel from '@/modules/task/components/TaskDetailPanel.vue';
import TaskRow from '@/modules/task/components/TaskRow.vue';
import { useTaskPanel } from '@/modules/task/composables/useTaskPanel';
import type { MyTaskRow, TaskAssignee, TaskDetail, TaskFeed } from '@/modules/task/types';
import { create as createTask } from '@/routes/tasks';

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
    /** The panel, when the URL says one is open. `null` rather than absent (TASK-200-004). */
    taskDetail?: TaskDetail | null;
    /** Deferred with the panel: absent until the follow-up request lands. */
    activity?: TaskFeed;
    members: TaskAssignee[];
    priorities: string[];
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
/**
 * The second line: what the first one implies but does not say. An empty Overdue is somebody
 * being on top of their work; an empty Today is a day with nothing scheduled — and those two
 * want different sentences under them, not the same shrug.
 */
const emptyDescriptions: Record<string, string> = {
    today: 'Nothing is scheduled for today in this workspace.',
    upcoming: 'Nothing with a date on it is waiting for you.',
    overdue: 'Nothing has slipped past its date.',
    completed: 'Tasks you finish appear here.',
};

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

/*
 * A row opens the panel here rather than navigating to the task's own page: the tab, the page and
 * the rows appended to it are the reader's place in a list, and leaving the screen to read one
 * task throws all three away.
 */
const { open, close: closeTask } = useTaskPanel();
</script>

<template>
    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <Head title="My Tasks" />

        <!-- The screen's name in the outline. The design carries it in the tab title
            and the sidebar rather than on the page, so it is announced rather than drawn. -->
        <h1 class="sr-only">My Tasks</h1>

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
                    :members="members"
                    :priorities="priorities"
                    :editable="can.updateTask"
                    @open="open"
                />

                <p v-if="task.projects.length" class="pl-7 text-xs text-muted-foreground">
                    <span v-for="project in task.projects" :key="project.id" class="mr-2">{{ project.name }}</span>
                </p>
            </li>
        </ul>

        <EmptyState
            v-else-if="!loading"
            :title="emptyMessages[meta.tab] ?? 'Nothing here.'"
            :description="emptyDescriptions[meta.tab]"
            :icon="CheckCheck"
        >
            <template v-if="meta.tab !== 'completed'" #action>
                <Link
                    :href="createTask()"
                    class="inline-flex h-9 items-center gap-1.5 rounded-md bg-primary px-3 text-[13px] font-semibold text-primary-foreground transition-colors hover:bg-primary-hover focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                >
                    <Plus class="size-4" />
                    Add a task
                </Link>
            </template>
        </EmptyState>

        <button
            v-if="meta.hasMore"
            type="button"
            class="self-start rounded border border-input px-2 py-1 text-xs"
            :disabled="loading"
            @click="loadMore"
        >
            {{ loading ? 'Loading…' : 'Load more' }}
        </button>

        <TaskDetailPanel
            v-if="taskDetail"
            :key="taskDetail.task.id"
            :detail="taskDetail"
            :members="members"
            :priorities="priorities"
            :activity="activity"
            @close="closeTask"
        />
    </div>
</template>
