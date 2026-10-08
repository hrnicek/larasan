<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, ChevronRight, GripVertical, MessageSquare } from '@lucide/vue';
import { computed, ref } from 'vue';
import TaskController from '@/actions/App/Http/Controllers/Task/TaskController';
import AssigneePicker from '@/modules/task/components/AssigneePicker.vue';
import DueDatePicker from '@/modules/task/components/DueDatePicker.vue';
import PriorityControl from '@/modules/task/components/PriorityControl.vue';
import TaskTextField from '@/modules/task/components/TaskTextField.vue';
import type { ListColumn } from '@/modules/task/listColumns';
import { defaultListColumns, listColumns } from '@/modules/task/listColumns';
import type { TaskAssignee, TaskRowData } from '@/modules/task/types';

const props = defineProps<{
    task: TaskRowData;
    members: TaskAssignee[];
    priorities: string[];
    editable: boolean;
    index?: number;
    dragging?: boolean;
    columns?: ListColumn[];
}>();

const emit = defineEmits<{
    open: [taskId: string];
    pickup: [event: PointerEvent, task: TaskRowData];
}>();

const columns = computed<ListColumn[]>(
    () => props.columns ?? defaultListColumns,
);

const answerOf = (fieldId: string, type: string | null): string => {
    const value = props.task.fields?.[fieldId];

    if (value === undefined || value === null || value === '') {
        return '—';
    }

    return type === 'boolean' ? (value ? '✓' : '—') : String(value);
};

const optimisticCompletion = ref<boolean | null>(null);
const pending = ref(false);

const completed = () =>
    optimisticCompletion.value ?? props.task.completedAt !== null;

function toggleCompletion(): void {
    if (!props.editable || pending.value) {
        return;
    }

    const wasCompleted = completed();
    optimisticCompletion.value = !wasCompleted;
    pending.value = true;

    const settle = {
        onFinish: () => {
            pending.value = false;
            optimisticCompletion.value = null;
        },
    };

    if (wasCompleted) {
        router.delete(TaskController.reopen.url(props.task.id), {
            preserveScroll: true,
            ...settle,
        });

        return;
    }

    router.put(
        TaskController.complete.url(props.task.id),
        {},
        { preserveScroll: true, ...settle },
    );
}
</script>

<template>
    <div
        tabindex="0"
        data-task-row
        :data-task-id="task.id"
        :data-placement-id="task.placementId"
        class="group/row relative flex flex-col gap-1 px-4 py-1.5 text-sm transition-colors outline-none hover:bg-muted/40 focus-visible:bg-accent md:flex-row md:items-stretch md:gap-0 md:px-0 md:py-0"
        :class="[
            completed() ? 'text-muted-foreground' : '',
            dragging ? 'opacity-50' : '',
        ]"
        @keydown.space.self.prevent="toggleCompletion"
        @keydown.enter.self="emit('open', task.id)"
    >
        <!-- `md` only: a drag inside a scrolling touch list fights the scroll. -->
        <button
            v-if="editable && task.placementId"
            type="button"
            class="absolute top-1/2 left-0 z-10 hidden size-5 -translate-y-1/2 cursor-grab items-center justify-center text-muted-foreground/60 opacity-0 transition-opacity group-hover/row:opacity-100 focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none active:cursor-grabbing md:flex"
            :aria-label="`Reorder ${task.title}`"
            @pointerdown="emit('pickup', $event, task)"
        >
            <GripVertical class="size-4" />
        </button>

        <span
            v-if="index !== undefined"
            class="hidden items-center text-xs text-muted-foreground/70 tabular-nums md:flex"
            :class="[listColumns.index, listColumns.cell]"
            aria-hidden="true"
        >
            {{ index }}
        </span>

        <div
            class="@container flex min-w-0 items-center gap-2 overflow-hidden md:cursor-pointer"
            :class="[listColumns.name, listColumns.cell, listColumns.hover]"
            @click.self="emit('open', task.id)"
        >
            <button
                v-if="editable"
                type="button"
                :disabled="pending"
                :aria-label="completed() ? 'Reopen task' : 'Complete task'"
                :aria-pressed="completed()"
                class="-m-2 flex size-11 cursor-pointer items-center justify-center disabled:cursor-not-allowed disabled:opacity-50 md:-m-0.5 md:size-6"
                @click="toggleCompletion"
            >
                <span
                    class="flex size-[18px] items-center justify-center rounded-full border transition-colors"
                    :class="
                        completed()
                            ? 'border-emerald-600 bg-emerald-600 text-white dark:border-emerald-500 dark:bg-emerald-500'
                            : 'border-input text-muted-foreground'
                    "
                    aria-hidden="true"
                >
                    <Check
                        class="size-3 transition-opacity"
                        :class="
                            completed()
                                ? 'opacity-100'
                                : 'opacity-0 group-hover/row:opacity-100'
                        "
                    />
                </span>
            </button>
            <span
                v-else
                class="flex size-[18px] shrink-0 items-center justify-center rounded-full border border-input"
                :class="
                    completed()
                        ? 'border-emerald-600 bg-emerald-600 text-white'
                        : ''
                "
                aria-hidden="true"
            >
                <Check v-if="completed()" class="size-3" />
            </span>

            <TaskTextField
                v-if="editable"
                :task-id="task.id"
                :value="task.title"
                :editable="editable"
                size="row"
                @click.stop
            />

            <span
                v-else
                class="flex min-h-11 min-w-0 items-center truncate md:min-h-6"
                >{{ task.title }}</span
            >

            <span
                v-if="task.comments > 0"
                class="inline-flex shrink-0 items-center gap-1 text-xs text-muted-foreground"
            >
                <MessageSquare class="size-3.5" aria-hidden="true" />
                {{ task.comments }}
                <span class="sr-only">comments</span>
            </span>

            <button
                type="button"
                class="ml-auto inline-flex size-11 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-opacity hover:bg-accent hover:text-foreground focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none md:size-6 md:opacity-0 md:group-focus-within/row:opacity-100 md:group-hover/row:opacity-100"
                :aria-label="`Open ${task.title}`"
                @click="emit('open', task.id)"
            >
                <ChevronRight class="size-4" />
            </button>
        </div>

        <!-- The second line below `md`; from `md`, `contents` makes its children cells of the row. -->
        <div class="flex items-center gap-3 pl-7 md:contents">
            <template v-for="column in columns" :key="column.key">
                <span
                    v-if="column.kind === 'field'"
                    class="hidden items-center text-xs text-muted-foreground md:flex"
                    :class="[
                        listColumns.field,
                        listColumns.cell,
                        listColumns.hover,
                    ]"
                    :title="`${column.label}: ${answerOf(column.key, column.type)}`"
                >
                    <span class="truncate">{{
                        answerOf(column.key, column.type)
                    }}</span>
                </span>

                <div
                    v-else-if="column.kind === 'assignee'"
                    class="flex items-center"
                    :class="[
                        listColumns.assignee,
                        listColumns.cell,
                        listColumns.hover,
                    ]"
                >
                    <AssigneePicker
                        :task-id="task.id"
                        :assignee="task.assignee"
                        :members="members"
                        :editable="editable"
                    />
                </div>

                <div
                    v-else-if="column.kind === 'due'"
                    class="flex items-center"
                    :class="[
                        listColumns.due,
                        listColumns.cell,
                        listColumns.hover,
                    ]"
                >
                    <DueDatePicker
                        :task-id="task.id"
                        :due-at="task.dueAt"
                        :editable="editable"
                    />
                </div>

                <div
                    v-else
                    class="flex items-center"
                    :class="[
                        listColumns.priority,
                        listColumns.cell,
                        listColumns.hover,
                    ]"
                >
                    <PriorityControl
                        :task-id="task.id"
                        :priority="task.priority"
                        :priorities="priorities"
                        :editable="editable"
                    />
                </div>
            </template>

            <span :class="listColumns.filler" />
        </div>
    </div>
</template>
