<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Check, ChevronRight, Search as SearchIcon } from '@lucide/vue';
import { ref, watch } from 'vue';
import SearchController from '@/actions/App/Http/Controllers/Search/SearchController';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import TaskDetailPanel from '@/modules/task/components/TaskDetailPanel.vue';
import { useTaskPanel } from '@/modules/task/composables/useTaskPanel';
import type { MyTaskRow, TaskAssignee, TaskDetail, TaskFeed } from '@/modules/task/types';

/**
 * Finding work.
 *
 * The term and the filters live in the URL, so a search is a link somebody can send. Typing is
 * debounced: a request per keystroke is a request per keystroke the server has to answer, and
 * nobody reads the results of the word they are halfway through.
 */
const props = defineProps<{
    tasks: MyTaskRow[];
    meta: { term: string; page: number; perPage: number; total: number; hasMore: boolean };
    filters: { project?: string; assignee?: number; completed?: boolean };
    /** The projects the filter offers. Not the sidebar's `projects` — see the controller. */
    filterProjects: { id: string; name: string }[];
    members: TaskAssignee[];
    /** The panel, when the URL says one is open. `null` rather than absent (TASK-200-004). */
    taskDetail?: TaskDetail | null;
    /** Deferred with the panel: absent until the follow-up request lands. */
    activity?: TaskFeed;
    priorities: string[];
}>();

const term = ref(props.meta.term);
const rows = ref<MyTaskRow[]>([...props.tasks]);

watch(() => props.tasks, (tasks) => {
    rows.value = props.meta.page === 1 ? [...tasks] : [...rows.value, ...tasks];
});

watch(() => props.meta.term, (value) => {
    // The server's term wins whenever it changes: a back button that put an old search in the
    // address should put it in the box too.
    if (value !== term.value) {
        term.value = value;
    }
});

let pending: ReturnType<typeof setTimeout> | null = null;

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

const run = (page = 1): void => {
    router.get(SearchController.index.url({ query: query(page) }), {}, {
        only: ['tasks', 'meta', 'filters'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const onTyping = (): void => {
    if (pending !== null) {
        clearTimeout(pending);
    }

    // Long enough that a word is finished, short enough that nobody wonders whether it is
    // working.
    pending = setTimeout(() => run(), 250);
};

const filterBy = (key: 'project' | 'assignee' | 'completed', value: string): void => {
    const params = query();

    if (value === '') {
        delete params[key];
    } else {
        params[key] = value;
    }

    router.get(SearchController.index.url({ query: params }));
};

/*
 * A result opens the panel at this screen's address, so the term, the filters and the page are
 * still there behind it — and still there when it closes. A search somebody has to retype after
 * reading one result is a search they run once.
 */
const { open, close: closeTask } = useTaskPanel();
</script>

<template>
    <div class="flex h-full flex-1 flex-col">
        <Head title="Search" />

        <PageHeader title="Search" description="Task names and descriptions, across every project you can reach" />

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

            <!-- The filters read as one row of controls at the same height as the box above
                 them, because narrowing a search is part of running it. -->
            <div class="flex flex-wrap gap-2">
                <select
                    :value="filters.project ?? ''"
                    aria-label="Project"
                    class="h-8 rounded-md border border-input bg-transparent px-2 text-[13px] focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                    @change="filterBy('project', ($event.target as HTMLSelectElement).value)"
                >
                    <option value="">Any project</option>
                    <option v-for="project in filterProjects" :key="project.id" :value="project.id">{{ project.name }}</option>
                </select>

                <select
                    :value="filters.assignee ?? ''"
                    aria-label="Assignee"
                    class="h-8 rounded-md border border-input bg-transparent px-2 text-[13px] focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                    @change="filterBy('assignee', ($event.target as HTMLSelectElement).value)"
                >
                    <option value="">Anybody</option>
                    <option v-for="member in members" :key="member.id" :value="member.id">{{ member.name }}</option>
                </select>

                <select
                    :value="filters.completed === undefined ? '' : String(filters.completed)"
                    aria-label="Completion"
                    class="h-8 rounded-md border border-input bg-transparent px-2 text-[13px] focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                    @change="filterBy('completed', ($event.target as HTMLSelectElement).value)"
                >
                    <option value="">Finished or not</option>
                    <option value="0">Still open</option>
                    <option value="1">Finished</option>
                </select>

                <p v-if="meta.term !== ''" class="ml-auto self-center text-xs text-muted-foreground">
                    {{ meta.total }} {{ meta.total === 1 ? 'result' : 'results' }}
                </p>
            </div>

            <!-- A result is a row, not a sentence: the name leads, where it lives follows it, and
                 the whole line is the target. -->
            <ul v-if="rows.length" class="flex flex-col divide-y divide-border border-y border-border">
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

                        <span class="min-w-0 flex-1 truncate" :class="task.completedAt ? 'text-muted-foreground line-through' : ''">
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

            <!-- Two different nothings: nothing typed yet, and nothing found. A search box that
                 says "no results" before anybody has typed looks broken. -->
            <EmptyState
                v-else
                :icon="SearchIcon"
                :title="meta.term === '' ? 'Search this workspace' : `Nothing matched “${meta.term}”`"
                :description="
                    meta.term === ''
                        ? 'Task names and descriptions, across every project you can reach.'
                        : 'Try fewer words, or part of one — a search matches the beginning of the last word you typed.'
                "
            />

            <button
                v-if="meta.hasMore"
                type="button"
                class="self-start rounded-md border border-input px-2.5 py-1.5 text-xs transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                @click="run(meta.page + 1)"
            >
                Load more
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
