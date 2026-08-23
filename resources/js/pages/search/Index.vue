<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import SearchController from '@/actions/App/Http/Controllers/Search/SearchController';
import TaskController from '@/actions/App/Http/Controllers/Task/TaskController';
import type { MyTaskRow, TaskAssignee } from '@/modules/task/types';

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
    projects: { id: string; name: string }[];
    members: TaskAssignee[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Search', href: SearchController.index.url() }] },
});

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

const open = (taskId: string): void => {
    router.get(TaskController.show.url(taskId));
};
</script>

<template>
    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <Head title="Search" />

        <input
            v-model="term"
            type="search"
            autofocus
            placeholder="Search tasks…"
            aria-label="Search tasks"
            class="w-full rounded border border-input bg-transparent px-2 py-1 text-sm"
            @input="onTyping"
            @keydown.enter.prevent="run()"
        />

        <div class="flex flex-wrap gap-2 text-xs">
            <select
                :value="filters.project ?? ''"
                aria-label="Project"
                class="rounded border border-input bg-transparent px-1 py-0.5"
                @change="filterBy('project', ($event.target as HTMLSelectElement).value)"
            >
                <option value="">Any project</option>
                <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.name }}</option>
            </select>

            <select
                :value="filters.assignee ?? ''"
                aria-label="Assignee"
                class="rounded border border-input bg-transparent px-1 py-0.5"
                @change="filterBy('assignee', ($event.target as HTMLSelectElement).value)"
            >
                <option value="">Anybody</option>
                <option v-for="member in members" :key="member.id" :value="member.id">{{ member.name }}</option>
            </select>

            <select
                :value="filters.completed === undefined ? '' : String(filters.completed)"
                aria-label="Completion"
                class="rounded border border-input bg-transparent px-1 py-0.5"
                @change="filterBy('completed', ($event.target as HTMLSelectElement).value)"
            >
                <option value="">Finished or not</option>
                <option value="0">Still open</option>
                <option value="1">Finished</option>
            </select>
        </div>

        <ul v-if="rows.length" class="flex flex-col gap-1">
            <li v-for="task in rows" :key="task.id">
                <button
                    type="button"
                    class="w-full rounded px-2 py-1 text-left text-sm hover:bg-muted"
                    @click="open(task.id)"
                >
                    <span :class="task.completedAt ? 'line-through' : ''">{{ task.title }}</span>

                    <span v-for="project in task.projects" :key="project.id" class="ml-2 text-xs text-muted-foreground">
                        {{ project.name }}
                    </span>
                </button>
            </li>
        </ul>

        <!-- Two different nothings: nothing typed yet, and nothing found. A search box that says
             "no results" before anybody has typed looks broken. -->
        <p v-else class="text-sm text-muted-foreground">
            {{ meta.term === '' ? 'Type to search this workspace.' : 'Nothing matched that.' }}
        </p>

        <button
            v-if="meta.hasMore"
            type="button"
            class="self-start rounded border border-input px-2 py-1 text-xs"
            @click="run(meta.page + 1)"
        >
            Load more
        </button>
    </div>
</template>
