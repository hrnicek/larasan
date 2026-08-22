<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import TaskController from '@/actions/App/Http/Controllers/Task/TaskController';
import AssigneePicker from '@/modules/task/components/AssigneePicker.vue';
import DueDatePicker from '@/modules/task/components/DueDatePicker.vue';
import PriorityControl from '@/modules/task/components/PriorityControl.vue';
import type { TaskAssignee, TaskRowData } from '@/modules/task/types';

/**
 * One task, in a line. Completion posts and waits: it is a domain state change with events
 * behind it, and a checkbox that ticks itself back a second later is worse than one that
 * takes a moment.
 */
const props = defineProps<{
    task: TaskRowData;
    members: TaskAssignee[];
    priorities: string[];
    editable: boolean;
}>();

const completed = () => props.task.completedAt !== null;
</script>

<template>
    <div class="flex items-center gap-3 px-4 py-2 text-sm" :class="completed() ? 'text-muted-foreground' : ''">
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
        <span v-else class="size-4 rounded border border-input" :class="completed() ? 'bg-muted' : ''" aria-hidden="true" />

        <span class="flex-1 truncate" :class="completed() ? 'line-through' : ''">{{ task.title }}</span>

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
</template>
