<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import TaskController from '@/actions/App/Http/Controllers/Task/TaskController';
import AssigneePicker from '@/modules/task/components/AssigneePicker.vue';
import DueDatePicker from '@/modules/task/components/DueDatePicker.vue';
import PriorityControl from '@/modules/task/components/PriorityControl.vue';
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
}>();

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
        class="flex flex-col gap-1 px-4 py-2 text-sm outline-none focus-visible:bg-accent md:flex-row md:items-center md:gap-3"
        :class="completed() ? 'text-muted-foreground' : ''"
        @keydown.space.prevent="toggleCompletion"
    >
        <div class="flex items-center gap-3">
            <Form
                v-if="editable"
                v-bind="completed() ? TaskController.reopen.form(task.id) : TaskController.complete.form(task.id)"
                #default="{ processing }"
            >
                <button
                    type="submit"
                    :disabled="processing"
                    :aria-label="completed() ? 'Reopen task' : 'Complete task'"
                    :aria-pressed="completed()"
                    class="size-4 rounded border border-input disabled:opacity-50"
                    :class="completed() ? 'bg-primary' : ''"
                />
            </Form>
            <span
                v-else
                class="size-4 rounded border border-input"
                :class="completed() ? 'bg-muted' : ''"
                aria-hidden="true"
            />

            <span class="flex-1 truncate" :class="completed() ? 'line-through' : ''">{{ task.title }}</span>
        </div>

        <!-- Below md this is the second line, indented past the checkbox so the name leads. -->
        <div class="flex items-center gap-3 pl-7 md:ml-auto md:pl-0">
            <AssigneePicker
                :task-id="task.id"
                :assignee="task.assignee"
                :members="members"
                :editable="editable"
            />
            <DueDatePicker :task-id="task.id" :due-at="task.dueAt" :editable="editable" />

            <PriorityControl
                :task-id="task.id"
                :priority="task.priority"
                :priorities="priorities"
                :editable="editable"
            />
        </div>
    </div>
</template>
