<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ListTodo, Plus } from '@lucide/vue';
import { computed, defineAsyncComponent, onMounted, onUnmounted, ref, watch } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import { useBoardDragAndDrop, useTaskDragAndDrop } from '@/composables/useBoardDragAndDrop';
import { useBoardKeyboardMove } from '@/composables/useBoardKeyboardMove';
import { useCalendarDrag } from '@/composables/useCalendarDrag';
import { useCollapsedSections } from '@/composables/useCollapsedSections';
import { useRealtime } from '@/composables/useRealtime';
import { useTaskListKeyboard } from '@/composables/useTaskListKeyboard';
import FieldSortControl from '@/modules/custom-field/components/FieldSortControl.vue';
import type { ProjectPages } from '@/modules/page/types';
import ProjectHeader from '@/modules/project/components/ProjectHeader.vue';
import type { ProjectCustomize, ProjectFiles, ProjectHeading, ProjectShare } from '@/modules/project/types';
import { BoardColumn, CalendarGrid, CalendarToolbar, FilesTable, PagesTree } from '@/modules/project/views';
import TagFilter from '@/modules/tag/components/TagFilter.vue';
import InlineTaskCreate from '@/modules/task/components/InlineTaskCreate.vue';
import SectionGroup from '@/modules/task/components/SectionGroup.vue';
import TaskListHeader from '@/modules/task/components/TaskListHeader.vue';
import { useTaskPanel } from '@/modules/task/composables/useTaskPanel';
import type {
    BoardColumnData,
    CalendarDay,
    ProjectBoard,
    ProjectCalendar,
    ProjectList,
    TaskFeed,
    TaskAssignee,
    TaskSectionGroup,
    TaskTag,
    TaskDetail,
} from '@/modules/task/types';
import { create as createTask } from '@/routes/tasks';

/*
 * The panel is the heaviest thing this screen can show and most visits never open one, so it is
 * not part of what the screen downloads to draw itself. `useTaskPanel` fetches it once the screen
 * is idle, which keeps opening a task instant without putting it on the critical path.
 */
const TaskDetailPanel = defineAsyncComponent(() => import('@/modules/task/components/TaskDetailPanel.vue'));

/**
 * The project's own screen: its list, its board, its month, its files or its pages, whichever the
 * URL asked for. One payload arrives, never two — the server reads what the view needs and
 * nothing else.
 */
const props = defineProps<{
    project: ProjectHeading;
    view: string;
    views: string[];
    // One of the five, decided by `view`: the server sends the payload the view asked for and
    // not the others, because reading the same placements twice is what "one screen, five
    // views" is supposed to avoid.
    list?: ProjectList;
    board?: ProjectBoard;
    calendar?: ProjectCalendar;
    files?: ProjectFiles;
    pages?: ProjectPages;
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
    /** Absent until the header's *Customize* drawer asks for it (`Inertia::optional`). */
    customize?: ProjectCustomize;
    /** Absent until the header's *Share* dialog asks for it, for the same reason. */
    share?: ProjectShare;
}>();

/*
 * The board's own copy of the columns, so a card can move before the server has agreed. It is
 * replaced whenever the server sends a new board — its answer wins over the optimistic one.
 */
const columns = ref<BoardColumnData[]>(props.board?.columns ?? []);

watch(() => props.board, (board) => {
    columns.value = board?.columns ?? [];
});

const editable = () => (props.board ?? props.list ?? props.calendar)?.can.updateTask === true;

/**
 * Which payload this screen is drawing, by the name the server sends it under. Read from the
 * props rather than from `view`, so a reload asks for the region that is actually on the screen
 * rather than the one a half-landed switch has named.
 */
const drawing = computed<string>(() =>
    props.board
        ? 'board'
        : props.calendar
          ? 'calendar'
          : props.files
            ? 'files'
            : props.pages
              ? 'pages'
              : 'list',
);

/*
 * Somebody else moved a card, renamed a column, commented or attached a file: refetch what this
 * screen draws and let the server answer (ADR-0008). Only the view props, so an open detail panel
 * is not thrown away by somebody else's edit elsewhere on the board.
 */
useRealtime({
    channels: () => [`project.${props.project.id}`],
    only: ['board', 'list', 'calendar', 'files', 'pages'],
});
const creatable = () => (props.board ?? props.list ?? props.calendar)?.can.createTask === true;

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
const { open: openTask, close: closeTask } = useTaskPanel();

const drag = useBoardDragAndDrop(columns, () => editable());

/*
 * The list's own copy of its sections, for the same reason the board keeps one: a row moves
 * locally, the request confirms it, and a refusal puts it back in the slot it came from. The
 * server's answer replaces it whenever a new list arrives.
 */
const sections = ref<TaskSectionGroup[]>(props.list?.sections ?? []);

watch(() => props.list, (list) => {
    sections.value = list?.sections ?? [];
});

const listDrag = useTaskDragAndDrop(sections, () => editable(), {
    cardSelector: '[data-task-row]',
    reloadKey: 'list',
});

/*
 * The calendar's own copy of the month and of its tray, for the reason the board and the list
 * keep theirs: a chip lands on a day before the server has agreed, and a refusal puts it back on
 * the day it came from. The server's answer replaces both whenever a new month arrives.
 */
const days = ref<CalendarDay[]>(props.calendar?.days ?? []);
const undated = ref<ProjectCalendar['undated']>(props.calendar?.undated ?? { count: 0, hasMore: false, tasks: [] });

watch(() => props.calendar, (calendar) => {
    days.value = calendar?.days ?? [];
    undated.value = calendar?.undated ?? { count: 0, hasMore: false, tasks: [] };
});

const calendarDrag = useCalendarDrag(days, undated, () => editable());

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

/**
 * Asking for one group in full — a column of the board, or a day of the calendar. Both spend the
 * same `expand` parameter, because a URL names one view and the two meanings can never meet.
 */
const expand = (group: string | null): void => {
    const key = group ?? 'ungrouped';
    const current = new URLSearchParams(window.location.search).getAll('expand[]');

    router.reload({
        only: [props.calendar ? 'calendar' : 'board'],
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
    ['list', 'board', 'calendar', 'files', 'pages'].some((key) => event.detail.visit.only.includes(key));

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
    router.reload({ only: [drawing.value] });
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
    <div class="flex flex-col">
        <Head :title="project.name" />

        <!--
            The project's name, its views, the toolbar and the list's column names are one block
            pinned to the top of the canvas: at the bottom of a long list you still need to know
            which project this is, which view you are in and what the fourth column means.
        -->
        <div class="sticky top-0 z-20 bg-background">
            <ProjectHeader
                :project="project"
                :view="view"
                :views="views"
                :customize="customize"
                :share="share"
            />

            <!--
                The toolbar: what this view is showing and how to change it, on one line above the
                content. Adding comes first because it is the thing done most.

                The files table and the pages tree have none of it. Every control here narrows or
                orders *tasks*, and
                a tag filter over a list of documents would answer a question about something the
                reader is not looking at.
            -->
            <div v-if="!files && !pages" class="flex flex-wrap items-center gap-2 px-4 py-3 md:px-6">
                <Link
                    v-if="creatable()"
                    :href="createTask({ query: { project: project.id } })"
                    class="inline-flex h-8 items-center gap-1.5 rounded-md bg-primary px-2.5 text-[13px] font-semibold text-primary-foreground transition-colors hover:bg-primary-hover focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                >
                    <Plus class="size-4" />
                    Add task
                </Link>

                <TagFilter
                    :project-id="project.id"
                    :view="view"
                    :month="calendar?.month"
                    :active="tags.active"
                    :available="tags.available"
                />

                <CalendarToolbar
                    v-if="calendar"
                    :project-id="project.id"
                    :calendar="calendar"
                    :tags="tags.active"
                    :editable="editable()"
                    :dragging-id="calendarDrag.draggingId.value"
                    class="w-full md:ml-2 md:w-auto md:flex-1"
                    @open="openTask"
                    @pickup="calendarDrag.pickUp"
                />

                <FieldSortControl
                    v-if="list?.fields.length"
                    :project-id="project.id"
                    :view="view"
                    :fields="list.fields"
                    :sort="sort"
                />
            </div>

            <TaskListHeader v-if="list && list.sections.length" :columns="list.columns" />
        </div>

        <div class="flex flex-col pb-6">
            <div class="flex flex-1 flex-col">
        <!-- The keyboard move path's feedback: a card that moves silently has not moved. -->
        <p v-if="board" class="sr-only" role="status" aria-live="polite">{{ keyboard.announcement.value }}</p>

        <nav v-if="board && columns.length > 1" class="flex gap-2 overflow-x-auto px-4 pt-4 md:hidden md:px-6" aria-label="Columns">
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

        <!--
            The board scrolls sideways and nothing else does, so the scrollbar is thin and tinted
            rather than the platform's default bar drawn across the whole width of the canvas.
        -->
        <div
            v-if="board"
            class="flex gap-4 overflow-x-auto px-4 pt-4 pb-3 md:px-6 [scrollbar-color:var(--color-border)_transparent] [scrollbar-width:thin]"
            @keydown="keyboard.onKeydown"
        >
            <BoardColumn
                v-for="(column, index) in columns"
                :key="column.id ?? 'ungrouped'"
                :column="column"
                :project-id="project.id"
                :editable="editable()"
                :creatable="creatable()"
                :can-section="board ? {
                    create: board.can.createSection,
                    update: board.can.updateSection,
                    delete: board.can.deleteSection,
                } : undefined"
                :loading="reloading"
                :dragging-id="drag.draggingId.value ?? keyboard.carrying.value"
                :over="drag.overColumn.value === (column.id ?? 'ungrouped')"
                :drop-target="drag.dropTarget.value"
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
                class="mx-4 mt-4 flex items-center justify-between rounded-lg border border-destructive/40 px-4 py-3 text-sm md:mx-6"
                role="alert"
            >
                <span>Something went wrong loading this project.</span>
                <button type="button" class="underline" @click="retry">Try again</button>
            </div>

            <div
                v-if="list.sections.length"
                ref="listElement"
                class="flex flex-col"
                @keydown="onKeydown"
            >
                <SectionGroup
                    v-for="section in sections"
                    :key="section.id ?? 'ungrouped'"
                    :section="section"
                    :members="members"
                    :priorities="priorities"
                    :editable="editable()"
                    :creatable="creatable()"
                    :project-id="project.id"
                    :columns="list?.columns"
                :can-section="list ? {
                    create: list.can.createSection,
                    update: list.can.updateSection,
                    delete: list.can.deleteSection,
                } : undefined"
                :dragging-id="listDrag.draggingId.value"
                :drop-target="listDrag.dropTarget.value"
                :collapsed="isCollapsed(section.id)"
                    :loading="reloading"
                    @toggle="toggle"
                    @open="openTask"
                @pickup="(event, task) => listDrag.pickUp(event, task)"
                />
            </div>

            <EmptyState
                v-else
                class="mx-4 mt-4 md:mx-6"
                :icon="ListTodo"
                title="This project is empty"
                :description="
                    creatable()
                        ? 'Add the first task, or give it columns in the project settings.'
                        : 'Nothing has been put in it yet.'
                "
            >
                <template v-if="creatable()" #action>
                    <InlineTaskCreate :project-id="project.id" :section-id="null" />
                </template>
            </EmptyState>
            </template>

        <CalendarGrid
            v-else-if="calendar"
            :calendar="{ ...calendar, days, undated }"
            :project-id="project.id"
            :editable="editable()"
            :creatable="creatable()"
            :dragging-id="calendarDrag.draggingId.value"
            :over-day="calendarDrag.overDay.value"
            :loading="reloading"
            class="mt-4"
            @open="openTask"
            @expand="expand"
            @pickup="calendarDrag.pickUp"
        />

        <!-- The fourth view: what hangs off this project's tasks. It opens the same panel the
             other three do, because a file is only ever reached through the task it is on. -->
        <FilesTable v-else-if="files" :files="files" :loading="reloading" @open="openTask" />

        <!-- The fifth: what was written beside the work rather than inside a task. -->
        <PagesTree v-else-if="pages" :project-id="project.id" :pages="pages" />
            </div>

            <!-- The panel teleports itself over the page; it is placed here so it is torn down
                 with the screen that owns the open task. -->
            <TaskDetailPanel
                v-if="taskDetail"
                :key="taskDetail.task.id"
                :detail="taskDetail"
                :members="members"
                :priorities="priorities"
                :activity="activity"
                @open="openTask"
                @close="closeTask"
            />
        </div>
    </div>
</template>
