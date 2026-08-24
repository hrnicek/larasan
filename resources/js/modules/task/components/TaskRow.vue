<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import { ChevronRight, MessageSquare } from '@lucide/vue';
import { ref } from 'vue';
import TaskController from '@/actions/App/Http/Controllers/Task/TaskController';
import { accentChipClass } from '@/lib/accentColor';
import AssigneePicker from '@/modules/task/components/AssigneePicker.vue';
import DueDatePicker from '@/modules/task/components/DueDatePicker.vue';
import PriorityControl from '@/modules/task/components/PriorityControl.vue';
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
    /** The project's field columns, when this row is drawn inside one (TASK-150-008). */
    fields?: { id: string; name: string; type: string }[];
}>();

const emit = defineEmits<{ open: [taskId: string] }>();

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
        class="group/row flex flex-col gap-1 px-4 py-1.5 text-sm outline-none transition-colors hover:bg-accent/40 focus-visible:bg-accent md:flex-row md:items-center md:gap-3"
        :class="completed() ? 'text-muted-foreground' : ''"
        @keydown.space.prevent="toggleCompletion"
        @keydown.enter="emit('open', task.id)"
    >
        <div class="flex items-center gap-3">
            <Form
                v-if="editable"
                v-bind="completed() ? TaskController.reopen.form(task.id) : TaskController.complete.form(task.id)"
                #default="{ processing }"
            >
                <!--
                    The hit area is 44px on a touch width and the drawn box stays 16px inside it.
                    A 16px checkbox is a 16px checkbox whatever the pointer is; what changes with
                    the pointer is how much room it needs around it.
                -->
                <button
                    type="submit"
                    :disabled="processing"
                    :aria-label="completed() ? 'Reopen task' : 'Complete task'"
                    :aria-pressed="completed()"
                    class="-m-2 flex size-11 items-center justify-center disabled:opacity-50 md:-m-0.5 md:size-5"
                >
                    <span
                        class="size-4 rounded border border-input"
                        :class="completed() ? 'bg-primary' : ''"
                        aria-hidden="true"
                    />
                </button>
            </Form>
            <span
                v-else
                class="size-4 rounded border border-input"
                :class="completed() ? 'bg-muted' : ''"
                aria-hidden="true"
            />

            <button
                type="button"
                class="flex min-h-11 flex-1 items-center truncate text-left md:min-h-6"
                :class="completed() ? 'line-through' : ''"
                @click="emit('open', task.id)"
            >
                {{ task.title }}
            </button>

            <!--
                Revealed rather than always drawn. The whole row already opens the task; this is
                the affordance that says so, and one of them per line, permanently, is noise in a
                list somebody is scanning.
            -->
            <button
                type="button"
                class="hidden size-6 shrink-0 items-center justify-center rounded-md text-muted-foreground opacity-0 transition-opacity group-focus-within/row:opacity-100 group-hover/row:opacity-100 hover:bg-accent hover:text-foreground focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none md:inline-flex"
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
