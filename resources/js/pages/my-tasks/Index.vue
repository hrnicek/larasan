<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { CheckCheck, Plus } from '@lucide/vue';
import { computed, defineAsyncComponent, ref } from 'vue';
import MyTasksController from '@/actions/App/Http/Controllers/Task/MyTasksController';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { usePagedRows } from '@/composables/usePagedRows';
import TaskListHeader from '@/modules/task/components/TaskListHeader.vue';
import TaskRow from '@/modules/task/components/TaskRow.vue';
import { useTaskPanel } from '@/modules/task/composables/useTaskPanel';
import type { MyTaskRow, TaskAssignee, TaskDetail, TaskFeed } from '@/modules/task/types';
import { create as createTask } from '@/routes/tasks';

const TaskDetailPanel = defineAsyncComponent(() => import('@/modules/task/components/TaskDetailPanel.vue'));

const props = defineProps<{
    tasks: MyTaskRow[];
    tabs: string[];
    meta: { tab: string; page: number; perPage: number; total: number; hasMore: boolean };
    taskDetail?: TaskDetail | null;
    /** Deferred; absent until the follow-up request lands. */
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

const { rows, hasMore, loading, loadFailed, loadMore } = usePagedRows({
    rows: () => props.tasks,
    meta: () => props.meta,
    url: (page) => MyTasksController.index.url({ query: { tab: props.meta.tab, page } }),
    only: ['tasks', 'meta'],
    placement: 'server',
    scope: () => props.meta.tab,
});

const switching = ref(false);

const show = (tab: string): void => {
    if (tab === props.meta.tab) {
        return;
    }

    rows.value = [];
    switching.value = true;

    router.get(
        MyTasksController.index.url({ query: { tab, page: 1 } }),
        {},
        {
            only: ['tasks', 'meta'],
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                switching.value = false;
            },
        },
    );
};

const { open, close: closeTask } = useTaskPanel();
</script>

<template>
    <div class="flex h-full flex-1 flex-col">
        <Head title="My Tasks" />

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

                    <p v-if="task.projects.length" class="pb-1 pl-7 text-xs text-muted-foreground md:pl-11">
                        <span v-for="project in task.projects" :key="project.id" class="mr-2">{{ project.name }}</span>
                    </p>
                </li>
            </ul>

            <EmptyState
                v-else-if="!switching"
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

            <p v-if="loadFailed" class="mx-4 flex items-center gap-2 text-sm text-muted-foreground md:mx-6" role="status">
                More tasks did not load.
                <button
                    type="button"
                    class="font-medium text-foreground underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                    @click="loadMore"
                >
                    Try again
                </button>
            </p>

            <button
                v-else-if="hasMore"
                type="button"
                class="mx-4 self-start rounded border border-input px-2 py-1 text-xs md:mx-6"
                :disabled="loading || switching"
                @click="loadMore"
            >
                {{ loading || switching ? 'Loading…' : 'Load more' }}
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
