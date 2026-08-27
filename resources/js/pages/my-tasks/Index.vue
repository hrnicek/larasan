<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { CheckCheck, Plus } from '@lucide/vue';
import { computed, defineAsyncComponent, ref, watch } from 'vue';
import MyTasksController from '@/actions/App/Http/Controllers/Task/MyTasksController';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import TaskListHeader from '@/modules/task/components/TaskListHeader.vue';
import TaskRow from '@/modules/task/components/TaskRow.vue';
import { useTaskPanel } from '@/modules/task/composables/useTaskPanel';
import type { MyTaskRow, TaskAssignee, TaskDetail, TaskFeed } from '@/modules/task/types';
import { create as createTask } from '@/routes/tasks';

/*
 * The panel is the heaviest thing this screen can show and most visits never open one, so it is
 * not part of what the screen downloads to draw itself. `useTaskPanel` fetches it once the screen
 * is idle, which keeps opening a task instant without putting it on the critical path.
 */
const TaskDetailPanel = defineAsyncComponent(() => import('@/modules/task/components/TaskDetailPanel.vue'));

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
    /** The panel, when the URL says one is open. `null` rather than absent (TASK-200-004). */
    taskDetail?: TaskDetail | null;
    /** Deferred with the panel: absent until the follow-up request lands. */
    activity?: TaskFeed;
    members: TaskAssignee[];
    priorities: string[];
}>();

const labels: Record<string, string> = {
    today: 'Today',
    upcoming: 'Upcoming',
    overdue: 'Overdue',
    completed: 'Completed',
    starred: 'Starred',
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
/** Which workspace this is answering for: My Tasks means something different in each. */
const workspaceName = computed<string | undefined>(() => usePage().props.workspace?.name);

const emptyDescriptions: Record<string, string> = {
    today: 'Nothing is scheduled for today in this workspace.',
    upcoming: 'Nothing with a date on it is waiting for you.',
    overdue: 'Nothing has slipped past its date.',
    completed: 'Tasks you finish appear here.',
    starred: 'Star a task from its panel to keep it here, whoever it belongs to.',
};

const emptyMessages: Record<string, string> = {
    today: 'Nothing due today.',
    upcoming: 'Nothing coming up.',
    overdue: 'Nothing overdue.',
    completed: 'Nothing finished yet.',
    starred: 'Nothing starred.',
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
    <div class="flex h-full flex-1 flex-col">
        <Head title="My Tasks" />

        <!-- Title, tabs and column names are one pinned block, as they are in a project: the tab
             you are on is what the rows below mean. -->
        <div class="sticky top-0 z-20 bg-background">
            <PageHeader title="My Tasks" :description="workspaceName">
                <template #tabs>
                    <nav class="-mb-px flex items-end gap-1 overflow-x-auto" aria-label="My Tasks views">
                        <button
                            v-for="tab in tabs"
                            :key="tab"
                            type="button"
                            class="shrink-0 border-b-2 px-3 pt-1 pb-2.5 text-sm font-medium whitespace-nowrap transition-colors focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                            :class="
                                tab === meta.tab
                                    ? 'border-primary text-foreground'
                                    : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground'
                            "
                            :aria-current="tab === meta.tab ? 'page' : undefined"
                            @click="show(tab)"
                        >
                            {{ labels[tab] ?? tab }}
                        </button>
                    </nav>
                </template>
            </PageHeader>

            <TaskListHeader v-if="rows.length" :numbered="false" />
        </div>

        <div class="flex flex-1 flex-col gap-4 pb-4">

        <ul v-if="rows.length" class="flex flex-col divide-y divide-border border-b border-border">
            <li v-for="task in rows" :key="task.id" class="flex flex-col">
                <TaskRow
                    :task="task"
                    :members="members"
                    :priorities="priorities"
                    :editable="task.canUpdate"
                    @open="open"
                />

                <!-- Where the task lives, under its name: this is the one screen that shows tasks
                     from several projects at once, so the project is part of reading the row. -->
                <p v-if="task.projects.length" class="pb-1 pl-7 text-xs text-muted-foreground md:pl-11">
                    <span v-for="project in task.projects" :key="project.id" class="mr-2">{{ project.name }}</span>
                </p>
            </li>
        </ul>

        <EmptyState
            v-else-if="!loading"
            class="mx-4 mt-4 md:mx-6"
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
            class="mx-4 self-start rounded border border-input px-2 py-1 text-xs md:mx-6"
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
            @open="open"
            @close="closeTask"
        />
        </div>
    </div>
</template>
