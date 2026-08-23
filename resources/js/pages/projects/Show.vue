<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { useBoardDragAndDrop } from '@/composables/useBoardDragAndDrop';
import { useBoardKeyboardMove } from '@/composables/useBoardKeyboardMove';
import { useCollapsedSections } from '@/composables/useCollapsedSections';
import { useRealtime } from '@/composables/useRealtime';
import { useTaskListKeyboard } from '@/composables/useTaskListKeyboard';
import FieldSortControl from '@/modules/custom-field/components/FieldSortControl.vue';
import BoardColumn from '@/modules/project/components/BoardColumn.vue';
import ProjectHeader from '@/modules/project/components/ProjectHeader.vue';
import TagFilter from '@/modules/tag/components/TagFilter.vue';
import InlineTaskCreate from '@/modules/task/components/InlineTaskCreate.vue';
import SectionGroup from '@/modules/task/components/SectionGroup.vue';
import TaskDetailPanel from '@/modules/task/components/TaskDetailPanel.vue';
import type {
    BoardColumnData,
    ProjectBoard,
    ProjectList,
    TaskFeed,
    TaskAssignee,
    TaskTag,
    TaskDetail,
} from '@/modules/task/types';

/**
 * The project's own screen. The board arrives in Phase 090; until then the switcher is
 * honest about it rather than rendering a list under the wrong name.
 */
const props = defineProps<{
    project: { id: string; name: string; slug: string; color: string | null; icon: string | null; archived: boolean };
    view: string;
    views: string[];
    // One of the two, decided by `view`: the server sends the payload the view asked for and
    // not the other, because reading the same placements twice is what "one screen, two
    // views" is supposed to avoid.
    list?: ProjectList;
    board?: ProjectBoard;
    members: TaskAssignee[];
    priorities: string[];
    /** What the server filtered by, and the vocabulary to filter with (TASK-140-005). */
    tags: { active: string[]; available: TaskTag[] };
    /** What the server ordered and narrowed by (TASK-150-009). */
    sort: { field: string | null; direction: string; filters: Record<string, string> };
    /** The open panel, when the URL names a task. */
    taskDetail?: TaskDetail | null;
    /** Deferred with the panel: absent until its own request lands. */
    activity?: TaskFeed;
}>();

/*
 * The board's own copy of the columns, so a card can move before the server has agreed. It is
 * replaced whenever the server sends a new board — its answer wins over the optimistic one.
 */
const columns = ref<BoardColumnData[]>(props.board?.columns ?? []);

watch(() => props.board, (board) => {
    columns.value = board?.columns ?? [];
});

const editable = () => (props.board ?? props.list)?.can.updateTask === true;

/*
 * Somebody else moved a card, renamed a column or commented: refetch what this screen draws
 * and let the server answer (ADR-0008). Only the two view props, so an open detail panel is
 * not thrown away by somebody else's edit elsewhere on the board.
 */
useRealtime({
    channels: () => [`project.${props.project.id}`],
    only: ['board', 'list'],
});
const creatable = () => (props.board ?? props.list)?.can.createTask === true;

/**
 * Asking for one column in full. The ids live in the URL, so the state survives a reload and
 * a shared link shows what the sender was looking at — the same rule the view switcher
 * follows.
 */
/*
 * The panel is a URL, not a piece of local state: `?task=` on this screen's own address. A
 * copied link reopens the same board with the same task; back closes it; forward reopens it.
 * The visit is partial — only `taskDetail` — so the list or board behind it is not re-read.
 */
const openTask = (taskId: string): void => {
    router.get(
        `${window.location.pathname}${window.location.search.replace(/([?&])task=[^&]*/, '$1').replace(/[?&]$/, '')}`,
        { ...Object.fromEntries(new URLSearchParams(window.location.search)), task: taskId },
        { only: ['taskDetail'], preserveState: true, preserveScroll: true },
    );
};

const closeTask = (): void => {
    window.history.back();
};

const drag = useBoardDragAndDrop(columns, () => editable());

/*
 * Below `md` the board shows one column at a time. A row of four columns on a phone is four
 * columns nobody can read, and a drag across a pager is a gesture nobody can land — which is
 * why every card carries a "Move…" action rather than relying on the drag.
 */
const activeColumn = ref(0);

/*
 * Which columns are drawn is a CSS question, not a JavaScript one: reading `window.innerWidth`
 * gives an answer that is right once and then stale — a resize left the desktop board showing
 * a single column, because nothing re-read the width.
 */
const columnVisibility = (index: number): string => (index === activeColumn.value ? 'flex' : 'hidden md:flex');
const keyboard = useBoardKeyboardMove(columns, () => editable(), drag);

const expand = (columnId: string | null): void => {
    const key = columnId ?? 'ungrouped';
    const current = new URLSearchParams(window.location.search).getAll('expand[]');

    router.reload({
        only: ['board'],
        data: { expand: [...current, key] },
    });
};

const { isCollapsed, toggle } = useCollapsedSections(props.project.id);

// Named for the element, not the prop: `list` is already the payload.
const listElement = ref<HTMLElement | null>(null);
const { onKeydown } = useTaskListKeyboard(() => listElement.value);

/*
 * The rows are part of the page rather than a deferred region: the whole list is one query
 * and one round trip, and deferring the main content would trade a fast page for a spinner.
 * What does take time is a reload of the list alone — the retry after an error, and whatever
 * later asks for more rows — so the skeleton stands in for exactly that.
 */
const reloading = ref(false);
const failed = ref(false);
const listening = (event: { detail: { visit: { only: string[] } } }) =>
    event.detail.visit.only.includes('list') || event.detail.visit.only.includes('board');

const started = (event: { detail: { visit: { only: string[] } } }) => {
    if (listening(event)) {
        reloading.value = true;
        failed.value = false;
    }
};

const finished = () => {
    reloading.value = false;
};

/*
 * Inertia v3's names: `invalid` became `httpException` and `exception` became `networkError`.
 * Both leave the rows that are already drawn alone — an error region that emptied the screen
 * would lose the reader's place to tell them something went wrong.
 */
const errored = () => {
    failed.value = true;
    reloading.value = false;
};

const retry = () => {
    router.reload({ only: props.board ? ['board'] : ['list'] });
};

const stops: Array<() => void> = [];

onMounted(() => {
    stops.push(
        router.on('start', started),
        router.on('finish', finished),
        router.on('httpException', errored),
        router.on('networkError', errored),
    );
});

onUnmounted(() => {
    stops.forEach((stop) => stop());
});
</script>

<template>
    <div class="flex flex-col space-y-6">
        <Head :title="project.name" />

        <ProjectHeader :project="project" :view="view" :views="views" />

        <FieldSortControl
            v-if="list?.fields.length"
            :project-id="project.id"
            :view="view"
            :fields="list.fields"
            :sort="sort"
        />

        <TagFilter
            :project-id="project.id"
            :view="view"
            :active="tags.active"
            :available="tags.available"
        />

        <div class="flex flex-col gap-6 md:flex-row md:items-start">
            <!--
                Below `md` the detail takes the screen: a panel next to a board on a phone is
                two things that both need the width. From `md` up it sits beside the content,
                which stays visible because that is the whole point of a panel.
            -->
            <div class="flex flex-1 flex-col space-y-6" :class="taskDetail ? 'hidden md:flex' : ''">
        <!-- The keyboard move path's feedback: a card that moves silently has not moved. -->
        <p v-if="board" class="sr-only" role="status" aria-live="polite">{{ keyboard.announcement.value }}</p>

        <nav v-if="board && columns.length > 1" class="flex gap-2 overflow-x-auto md:hidden" aria-label="Columns">
            <button
                v-for="(column, index) in columns"
                :key="column.id ?? 'ungrouped'"
                type="button"
                class="rounded border px-3 py-1 text-xs"
                :class="index === activeColumn ? 'bg-accent text-accent-foreground' : 'text-muted-foreground'"
                :aria-current="index === activeColumn ? 'true' : undefined"
                @click="activeColumn = index"
            >
                {{ column.name ?? 'No section' }} ({{ column.count }})
            </button>
        </nav>

        <div v-if="board" class="flex gap-4 overflow-x-auto pb-2" @keydown="keyboard.onKeydown">
            <BoardColumn
                v-for="(column, index) in columns"
                :key="column.id ?? 'ungrouped'"
                :column="column"
                :project-id="project.id"
                :editable="editable()"
                :creatable="creatable()"
                :loading="reloading"
                :dragging-id="drag.draggingId.value ?? keyboard.carrying.value"
                :over="drag.overColumn.value === (column.id ?? 'ungrouped')"
                :columns="columns"
                class="w-full md:w-72"
                :class="columnVisibility(index)"
                @expand="expand"
                @pickup="drag.pickUp"
                @moveto="drag.moveTo"
                @open="openTask"
            />
        </div>

        <template v-else-if="list">
            <div
                v-if="failed"
                class="flex items-center justify-between rounded-lg border border-destructive/40 px-4 py-3 text-sm"
                role="alert"
            >
                <span>Something went wrong loading this project.</span>
                <button type="button" class="underline" @click="retry">Try again</button>
            </div>

            <div
                v-if="list.sections.length"
                ref="listElement"
                class="flex flex-col space-y-4"
                @keydown="onKeydown"
            >
                <SectionGroup
                    v-for="section in list.sections"
                    :key="section.id ?? 'ungrouped'"
                    :section="section"
                    :members="members"
                    :priorities="priorities"
                    :editable="editable()"
                    :creatable="creatable()"
                    :project-id="project.id"
                    :fields="list?.fields"
                :collapsed="isCollapsed(section.id)"
                    :loading="reloading"
                    @toggle="toggle"
                    @open="openTask"
                />
            </div>

            <div v-else class="flex flex-col items-center gap-3 rounded-lg border border-dashed px-4 py-10 text-center">
                <p class="text-sm text-muted-foreground">This project has no tasks and no columns yet.</p>
                <InlineTaskCreate v-if="creatable()" :project-id="project.id" :section-id="null" />
            </div>
            </template>
            </div>

            <!-- The panel: full width on a phone, a column beside the content from `md` up. -->
            <TaskDetailPanel
                v-if="taskDetail"
                :key="taskDetail.task.id"
                class="w-full md:w-96 md:shrink-0"
                :detail="taskDetail"
                :dismissible="true"
                :members="members"
                :priorities="priorities"
                :activity="activity"
                @close="closeTask"
            />
        </div>
    </div>
</template>
