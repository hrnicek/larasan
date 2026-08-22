<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import TaskController from '@/actions/App/Http/Controllers/Task/TaskController';

/**
 * The due date, changed from the row. Only this field is sent: `tasks.update` treats an
 * absent field as untouched, so a date change cannot clear a description the row never
 * carried.
 */
const props = defineProps<{
    taskId: string;
    dueAt: string | null;
    editable: boolean;
}>();

const saving = ref(false);

function change(event: Event): void {
    const value = (event.target as HTMLInputElement).value;

    saving.value = true;

    router.put(
        TaskController.update.url(props.taskId),
        // Empty means cleared, which is a null the server is allowed to act on.
        { due_at: value === '' ? null : value },
        { preserveScroll: true, onFinish: () => {
 saving.value = false; 
} },
    );
}
</script>

<template>
    <span v-if="!editable" class="text-xs text-muted-foreground">
        {{ dueAt ? dueAt.slice(0, 10) : '—' }}
    </span>

    <input
        v-else
        type="date"
        :value="dueAt ? dueAt.slice(0, 10) : ''"
        :disabled="saving"
        aria-label="Due date"
        class="rounded bg-transparent px-1 text-xs text-muted-foreground disabled:opacity-50"
        @change="change"
    />
</template>
