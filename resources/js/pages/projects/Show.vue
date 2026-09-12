<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
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
import InlineSectionCreate from '@/modules/project/components/InlineSectionCreate.vue';
import ProjectHeader from '@/modules/project/components/ProjectHeader.vue';
import ProjectViewSkeleton from '@/modules/project/components/ProjectViewSkeleton.vue';
import { rememberLandingView } from '@/modules/project/landingView';
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

const TaskDetailPanel = defineAsyncComponent(() => import('@/modules/task/components/TaskDetailPanel.vue'));

const props = defineProps<{
    project: ProjectHeading;
    view: string;
    views: string[];
    list?: ProjectList;
    board?: ProjectBoard;
    calendar?: ProjectCalendar;
    files?: ProjectFiles;
    pages?: ProjectPages;
    members: TaskAssignee[];
    priorities: string[];
    tags: { active: string[]; available: TaskTag[] };
    sort: { field: string | null; direction: string; filters: Record<string, string> };
    taskDetail?: TaskDetail | null;
    /** Deferred; absent until the follow-up request lands. */
    activity?: TaskFeed;
    /** Optional prop; absent until the Customize drawer requests it. */
    customize?: ProjectCustomize;
    /** Optional prop; absent until the Share dialog requests it. */
    share?: ProjectShare;
}>();

// Local copy for optimistic moves; replaced whenever the server sends a new board.
const columns = ref<BoardColumnData[]>(props.board?.columns ?? []);

watch(() => props.board, (board) => {
    columns.value = board?.columns ?? [];
});

const editable = () => (props.board ?? props.list ?? props.calendar)?.can.updateTask === true;

// Derived from the payload props rather than `view`, so a reload targets the region actually on screen.
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

const page = usePage();

// Only the server knows the default view, so it is remembered for the skeleton of the next instant visit.
watch(
    () => props.view,
    (view) => {
        const address = new URL(page.url, window.location.origin);

        if (!address.searchParams.has('view')) {
            rememberLandingView(address.pathname, view);
        }
    },
    { immediate: true },
);

// Only the view props are reloaded, so another user's edit never discards an open task panel. See ADR-0008.
useRealtime({
    channels: () => [`project.${props.project.id}`],
    only: ['board', 'list', 'calendar', 'files', 'pages'],
});
const creatable = () => (props.board ?? props.list ?? props.calendar)?.can.createTask === true;

const { open: openTask, close: closeTask } = useTaskPanel();

const drag = useBoardDragAndDrop(columns, () => editable());

const sections = ref<TaskSectionGroup[]>(props.list?.sections ?? []);

watch(() => props.list, (list) => {
    sections.value = list?.sections ?? [];
});

const listDrag = useTaskDragAndDrop(sections, () => editable(), {
    cardSelector: '[data-task-row]',
    reloadKey: 'list',
});

const days = ref<CalendarDay[]>(props.calendar?.days ?? []);
const undated = ref<ProjectCalendar['undated']>(props.calendar?.undated ?? { count: 0, hasMore: false, tasks: [] });

watch(() => props.calendar, (calendar) => {
    days.value = calendar?.days ?? [];
    undated.value = calendar?.undated ?? { count: 0, hasMore: false, tasks: [] };
});

const calendarDrag = useCalendarDrag(days, undated, () => editable());

const activeColumn = ref(0);

// Left to CSS breakpoints; reading window.innerWidth would go stale on resize.
const columnVisibility = (index: number): string => (index === activeColumn.value ? 'flex' : 'hidden md:flex');
const keyboard = useBoardKeyboardMove(columns, () => editable(), drag);

// Board columns and calendar days share the `expand` parameter; a URL only ever names one view.
const expand = (group: string | null): void => {
    const key = group ?? 'ungrouped';
    const current = new URLSearchParams(window.location.search).getAll('expand[]');

    router.reload({
        only: [props.calendar ? 'calendar' : 'board'],
        data: { expand: [...current, key] },
    });
};

const { isCollapsed, toggle } = useCollapsedSections(props.project.id);

const listElement = ref<HTMLElement | null>(null);
const { onKeydown } = useTaskListKeyboard(() => listElement.value);

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

        <div class="sticky top-0 z-20 bg-background">
            <ProjectHeader
                :project="project"
                :view="view"
                :views="views"
                :customize="customize"
                :share="share"
            />

            <div v-if="view !== 'files' && view !== 'pages'" class="flex flex-wrap items-center gap-2 px-4 py-3 md:px-6">
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

            <InlineSectionCreate
                v-if="board.can.createSection"
                :project-id="project.id"
                variant="board"
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
                    :siblings="sections.map((group) => group.id)"
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

                <InlineSectionCreate
                    v-if="list.can.createSection"
                    :project-id="project.id"
                    variant="list"
                />
            </div>

            <EmptyState
                v-else
                class="mx-4 mt-4 md:mx-6"
                :icon="ListTodo"
                title="This project is empty"
                :description="
                    creatable()
                        ? 'Add the first task, or give it a section to group them under.'
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

        <FilesTable v-else-if="files" :files="files" :loading="reloading" @open="openTask" />

        <PagesTree v-else-if="pages" :project-id="project.id" :pages="pages" />

        <!-- The view has switched but its payload has not arrived yet. -->
        <ProjectViewSkeleton v-else data-screen-pending :view="view" />
            </div>

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
