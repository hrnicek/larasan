<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import { Check, ChevronRight, GripVertical, MessageSquare } from '@lucide/vue';
import { ref } from 'vue';
import TaskController from '@/actions/App/Http/Controllers/Task/TaskController';
import { accentChipClass } from '@/lib/accentColor';
import AssigneePicker from '@/modules/task/components/AssigneePicker.vue';
import DueDatePicker from '@/modules/task/components/DueDatePicker.vue';
import PriorityControl from '@/modules/task/components/PriorityControl.vue';
import TaskTextField from '@/modules/task/components/TaskTextField.vue';
import { listColumns } from '@/modules/task/listColumns';
import type { TaskAssignee, TaskRowData } from '@/modules/task/types';

/**
 * One task, in a line. Completion posts and waits: it is a domain state change with events
 * behind it, and a checkbox that ticks itself back a second later is worse than one that
 * takes a moment.
 *
 * The row itself is focusable, so the list can be walked with the keyboard. Below `md` the
 * secondary fields drop to a second line under the name rather than being hidden: a phone
 * has less room, not less to say.
 */
const props = defineProps<{
    task: TaskRowData;
    members: TaskAssignee[];
    priorities: string[];
    editable: boolean;
    /** Its place in the section, drawn as a number so a row can be referred to out loud. */
    index?: number;
    /** Whether this row is the one currently being dragged. */
    dragging?: boolean;
    /** The project's field columns, when this row is drawn inside one (TASK-150-008). */
    fields?: { id: string; name: string; type: string }[];
}>();

const emit = defineEmits<{ open: [taskId: string]; pickup: [event: PointerEvent, task: TaskRowData] }>();

/**
 * A field's answer as a person reads it. A boolean is a tick rather than the word "true", and a
 * field nobody answered is a dash rather than a gap you would have to count columns to
 * interpret.
 */
const answerOf = (fieldId: string, type: string): string => {
    const value = props.task.fields?.[fieldId];

    if (value === undefined || value === null || value === '') {
        return '—';
    }

    return type === 'boolean' ? (value ? '✓' : '—') : String(value);
};

const row = ref<HTMLElement | null>(null);

const completed = () => props.task.completedAt !== null;

function toggleCompletion(): void {
    if (!props.editable) {
        return;
    }

    if (completed()) {
        router.delete(TaskController.reopen.url(props.task.id), { preserveScroll: true });

        return;
    }

    router.put(TaskController.complete.url(props.task.id), {}, { preserveScroll: true });
}

defineExpose({ focus: () => row.value?.focus() });
</script>

<template>
    <div
        ref="row"
        tabindex="0"
        data-task-row
        :data-task-id="task.id"
        :data-placement-id="task.placementId"
        class="group/row relative flex flex-col gap-1 px-4 py-1.5 text-sm outline-none transition-colors hover:bg-accent/40 focus-visible:bg-accent md:flex-row md:items-center md:gap-3"
        :class="[completed() ? 'text-muted-foreground' : '', dragging ? 'opacity-50' : '']"
        @keydown.space.prevent="toggleCompletion"
        @keydown.enter.self="emit('open', task.id)"
    >
        <!--
            The grip, revealed on hover. Reordering is a pointer gesture with a keyboard
            alternative behind it (`useTaskListKeyboard`), so the handle is the affordance rather
            than the mechanism — and it is `md`-only, because a drag inside a scrolling phone
            list fights the scroll.
        -->
        <button
            v-if="editable && task.placementId"
            type="button"
            class="absolute top-1/2 left-0 hidden size-5 -translate-y-1/2 cursor-grab items-center justify-center text-muted-foreground/60 opacity-0 transition-opacity group-hover/row:opacity-100 focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none active:cursor-grabbing md:flex"
            :aria-label="`Reorder ${task.title}`"
            @pointerdown="emit('pickup', $event, task)"
        >
            <GripVertical class="size-4" />
        </button>

        <div class="flex items-center gap-3">
            <!-- A row you can name out loud. Hidden below `md`, where the row is two lines and a
                 column of numbers is width spent on something nobody is counting on a phone. -->
            <span
                v-if="index !== undefined"
                class="hidden w-5 shrink-0 text-right text-xs tabular-nums text-muted-foreground/70 md:inline"
                aria-hidden="true"
            >
                {{ index }}
            </span>

            <Form
                v-if="editable"
                v-bind="completed() ? TaskController.reopen.form(task.id) : TaskController.complete.form(task.id)"
                #default="{ processing }"
            >
                <!--
                    The hit area is 44px on a touch width and the drawn circle stays 18px inside
                    it. A control is drawn at the size it should be read at; what changes with the
                    pointer is how much room it needs around it.

                    The check is present before it is true, at zero opacity, and appears under the
                    pointer. That is the affordance: a bare circle says "status", a circle with a
                    tick waiting inside it says "you can finish this".
                -->
                <button
                    type="submit"
                    :disabled="processing"
                    :aria-label="completed() ? 'Reopen task' : 'Complete task'"
                    :aria-pressed="completed()"
                    class="-m-2 flex size-11 items-center justify-center disabled:opacity-50 md:-m-0.5 md:size-6"
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
                            :class="completed() ? 'opacity-100' : 'opacity-0 group-hover/row:opacity-100'"
                        />
                    </span>
                </button>
            </Form>
            <span
                v-else
                class="flex size-[18px] items-center justify-center rounded-full border border-input"
                :class="completed() ? 'border-emerald-600 bg-emerald-600 text-white' : ''"
                aria-hidden="true"
            >
                <Check v-if="completed()" class="size-3" />
            </span>

            <!--
                The name is the field, not a link. Renaming is the thing done most often to a row
                and it used to need the panel; opening the task is the `›` beside it and `Enter`
                on the row, which is what the reference does too.
            -->
            <TaskTextField
                v-if="editable"
                class="flex-1"
                :task-id="task.id"
                field="title"
                :value="task.title"
                :editable="editable"
                size="row"
                @click.stop
            />

            <span v-else class="flex min-h-11 flex-1 items-center truncate md:min-h-6">{{ task.title }}</span>

            <!--
                Revealed rather than always drawn. The whole row already opens the task; this is
                the affordance that says so, and one of them per line, permanently, is noise in a
                list somebody is scanning.
            -->
            <button
                type="button"
                class="inline-flex size-11 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-opacity hover:bg-accent hover:text-foreground focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none md:size-6 md:opacity-0 md:group-focus-within/row:opacity-100 md:group-hover/row:opacity-100"
                :aria-label="`Open ${task.title}`"
                @click="emit('open', task.id)"
            >
                <ChevronRight class="size-4" />
            </button>
        </div>

        <!-- Beside the name, because a tag says what the task is about. -->
        <div v-if="task.tags.length" class="flex shrink-0 flex-wrap gap-1 pl-7 md:pl-0">
            <span
                v-for="tag in task.tags"
                :key="tag.id"
                class="rounded px-1.5 py-0.5 text-[11px] font-medium"
                :class="accentChipClass(tag.color)"
            >
                {{ tag.name }}
            </span>
        </div>

        <!-- Below md this is the second line, indented past the checkbox so the name leads. From
             `md` the cells are fixed columns, so the header above the list lines up with them. -->
        <div class="flex items-center gap-3 pl-7 md:ml-auto md:pl-0">
            <span v-if="task.comments > 0" class="inline-flex items-center gap-1 text-xs text-muted-foreground">
                <MessageSquare class="size-3.5" aria-hidden="true" />
                {{ task.comments }}
                <span class="sr-only">comments</span>
            </span>

            <span
                v-for="field in fields"
                :key="field.id"
                class="hidden text-xs text-muted-foreground md:inline"
                :class="listColumns.field"
                :title="field.name"
            >
                {{ answerOf(field.id, field.type) }}
            </span>

            <div :class="listColumns.assignee">
                <AssigneePicker
                    :task-id="task.id"
                    :assignee="task.assignee"
                    :members="members"
                    :editable="editable"
                />
            </div>

            <div :class="listColumns.due">
                <DueDatePicker :task-id="task.id" :due-at="task.dueAt" :editable="editable" />
            </div>

            <div :class="listColumns.priority">
                <PriorityControl
                    :task-id="task.id"
                    :priority="task.priority"
                    :priorities="priorities"
                    :editable="editable"
                />
            </div>
        </div>
    </div>
</template>
