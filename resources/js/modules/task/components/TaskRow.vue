<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import TaskController from '@/actions/App/Http/Controllers/Task/TaskController';
import type { TaskRowData } from '@/modules/task/types';

/**
 * One task, in a line. Completion posts and waits: it is a domain state change with events
 * behind it, and a checkbox that ticks itself back a second later is worse than one that
 * takes a moment.
 */
const props = defineProps<{
    task: TaskRowData;
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

        <span v-if="task.assignee" class="text-xs text-muted-foreground">{{ task.assignee.name }}</span>
        <span v-if="task.dueAt" class="text-xs text-muted-foreground">{{ task.dueAt.slice(0, 10) }}</span>
        <span class="text-xs capitalize text-muted-foreground">{{ task.priority }}</span>
    </div>
</template>
