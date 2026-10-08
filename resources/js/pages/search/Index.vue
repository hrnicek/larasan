<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Bookmark,
    Check,
    ChevronRight,
    Search as SearchIcon,
} from '@lucide/vue';
import {
    computed,
    defineAsyncComponent,
    onBeforeUnmount,
    ref,
    watch,
} from 'vue';
import SearchController from '@/actions/App/Http/Controllers/Search/SearchController';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { usePagedRows } from '@/composables/usePagedRows';
import SaveSearchDialog from '@/modules/search/components/SaveSearchDialog.vue';
import { useTaskPanel } from '@/modules/task/composables/useTaskPanel';
import type {
    MyTaskRow,
    TaskAssignee,
    TaskDetail,
    TaskFeed,
} from '@/modules/task/types';

const TaskDetailPanel = defineAsyncComponent(
    () => import('@/modules/task/components/TaskDetailPanel.vue'),
);

const props = defineProps<{
    tasks: MyTaskRow[];
    meta: {
        term: string;
        page: number;
        perPage: number;
        total: number;
        hasMore: boolean;
        /** Served by the database fallback because the search engine was unreachable. */
        degraded: boolean;
        /** The engine had more matches than requested, so `total` is a lower bound. */
        capped: boolean;
    };
    filters: { project?: string; assignee?: number; completed?: boolean };
    /** Distinct from the shared sidebar `projects` prop. */
    filterProjects: { id: string; name: string }[];
    members: TaskAssignee[];
    taskDetail?: TaskDetail | null;
    /** Deferred; absent until the follow-up request lands. */
    activity?: TaskFeed;
    priorities: string[];
}>();

const term = ref(props.meta.term);

watch(
    () => props.meta.term,
    (value) => {
        if (value !== term.value) {
            term.value = value;
        }
    },
);

let pending: ReturnType<typeof setTimeout> | null = null;

const cancelPending = (): void => {
    if (pending !== null) {
        clearTimeout(pending);
        pending = null;
    }
};

onBeforeUnmount(cancelPending);

const query = (page = 1): Record<string, string | number | boolean> => {
    const params: Record<string, string | number | boolean> = { q: term.value };

    if (props.filters.project !== undefined) {
        params.project = props.filters.project;
    }

    if (props.filters.assignee !== undefined) {
        params.assignee = props.filters.assignee;
    }

    if (props.filters.completed !== undefined) {
        params.completed = props.filters.completed;
    }

    if (page > 1) {
        params.page = page;
    }

    return params;
};

const { rows, hasMore, loading, loadFailed, loadMore } = usePagedRows({
    rows: () => props.tasks,
    meta: () => props.meta,
    url: (page) =>
        SearchController.index.url({
            query: { ...query(page), q: props.meta.term },
        }),
    only: ['tasks', 'meta', 'filters'],
    placement: 'server',
    scope: () => props.meta.term,
});

const run = (): void => {
    cancelPending();

    router.get(
        SearchController.index.url({ query: query() }),
        {},
        {
            only: ['tasks', 'meta', 'filters'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
};

const onTyping = (): void => {
    cancelPending();

    pending = setTimeout(run, 250);
};

const filterBy = (
    key: 'project' | 'assignee' | 'completed',
    value: string,
): void => {
    const params = query();

    if (value === '') {
        delete params[key];
    } else {
        params[key] = value;
    }

    router.get(SearchController.index.url({ query: params }));
};

const { open, close: closeTask } = useTaskPanel();

const canKeep = computed(() => props.meta.term !== '');

const keeping = ref(false);
</script>

<template>
    <div class="flex h-full flex-1 flex-col">
        <Head title="Search" />

        <PageHeader
            title="Search"
            description="Task names and descriptions, across every project you can reach"
        />

        <div class="flex flex-1 flex-col gap-4 px-4 py-4 md:px-6">
            <input
                v-model="term"
                type="search"
                autofocus
                placeholder="Search tasks…"
                aria-label="Search tasks"
                class="h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                @input="onTyping"
                @keydown.enter.prevent="run()"
            />

            <div class="flex flex-wrap gap-2">
                <select
                    :value="filters.project ?? ''"
                    aria-label="Project"
                    class="h-8 rounded-md border border-input bg-transparent px-2 text-[13px] focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                    @change="
                        filterBy(
                            'project',
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="">Any project</option>
                    <option
                        v-for="project in filterProjects"
                        :key="project.id"
                        :value="project.id"
                    >
                        {{ project.name }}
                    </option>
                </select>

                <select
                    :value="filters.assignee ?? ''"
                    aria-label="Assignee"
                    class="h-8 rounded-md border border-input bg-transparent px-2 text-[13px] focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                    @change="
                        filterBy(
                            'assignee',
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="">Anybody</option>
                    <option
                        v-for="member in members"
                        :key="member.id"
                        :value="member.id"
                    >
                        {{ member.name }}
                    </option>
                </select>

                <select
                    :value="
                        filters.completed === undefined
                            ? ''
                            : String(filters.completed)
                    "
                    aria-label="Completion"
                    class="h-8 rounded-md border border-input bg-transparent px-2 text-[13px] focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                    @change="
                        filterBy(
                            'completed',
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="">Finished or not</option>
                    <option value="0">Still open</option>
                    <option value="1">Finished</option>
                </select>

                <button
                    v-if="canKeep"
                    type="button"
                    class="ml-auto inline-flex h-8 items-center gap-1.5 rounded-md border border-input px-2.5 text-[13px] transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                    @click="keeping = true"
                >
                    <Bookmark class="size-3.5" />
                    Save search
                </button>

                <p
                    v-if="meta.term !== ''"
                    class="self-center text-xs text-muted-foreground"
                    :class="canKeep ? '' : 'ml-auto'"
                >
                    {{ meta.capped ? `${meta.total}+` : meta.total }}
                    {{ meta.total === 1 ? 'result' : 'results' }}
                </p>
            </div>

            <p
                v-if="meta.degraded && meta.term !== ''"
                class="rounded-md border border-border bg-muted/40 px-3 py-2 text-xs text-muted-foreground"
            >
                The search engine is unavailable, so these results are matched
                by whole words and without tolerance for typing mistakes.
            </p>

            <ul
                v-if="rows.length"
                class="flex flex-col divide-y divide-border border-y border-border"
            >
                <li v-for="task in rows" :key="task.id">
                    <button
                        type="button"
                        class="group/row flex min-h-11 w-full items-center gap-3 px-2 py-2 text-left text-sm transition-colors hover:bg-accent/40 focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                        @click="open(task.id)"
                    >
                        <Check
                            v-if="task.completedAt"
                            class="size-4 shrink-0 text-emerald-600 dark:text-emerald-500"
                            aria-hidden="true"
                        />

                        <span
                            class="min-w-0 flex-1 truncate"
                            :class="
                                task.completedAt
                                    ? 'text-muted-foreground line-through'
                                    : ''
                            "
                        >
                            {{ task.title }}
                        </span>

                        <span
                            v-for="project in task.projects"
                            :key="project.id"
                            class="hidden shrink-0 rounded bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground sm:inline"
                        >
                            {{ project.name }}
                        </span>

                        <ChevronRight
                            class="size-4 shrink-0 text-muted-foreground transition-opacity md:opacity-0 md:group-hover/row:opacity-100"
                            aria-hidden="true"
                        />
                    </button>
                </li>
            </ul>

            <EmptyState
                v-else
                :icon="SearchIcon"
                :title="
                    meta.term === ''
                        ? 'Search this workspace'
                        : `Nothing matched “${meta.term}”`
                "
                :description="
                    meta.term === ''
                        ? 'Task names and descriptions, across every project you can reach.'
                        : 'Try fewer words, or part of one — a search matches the beginning of the last word you typed.'
                "
            />

            <p
                v-if="loadFailed"
                class="flex items-center gap-2 text-sm text-muted-foreground"
                role="status"
            >
                More results did not load.
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
                class="self-start rounded-md border border-input px-2.5 py-1.5 text-xs transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                :disabled="loading"
                @click="loadMore"
            >
                {{ loading ? 'Loading…' : 'Load more' }}
            </button>

            <SaveSearchDialog
                v-model:open="keeping"
                :term="meta.term"
                :filters="filters"
            />

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
