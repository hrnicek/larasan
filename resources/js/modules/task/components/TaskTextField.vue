<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import TaskController from '@/actions/App/Http/Controllers/Task/TaskController';

/**
 * A task's title or description, edited where it is read.
 *
 * The rule that matters is the failure one: **a failed save never discards what was typed.**
 * The field keeps the text, says so, and offers to try again — an editor that throws away a
 * paragraph because the network blinked is one people copy out of before using.
 *
 * Only this field is sent. `tasks.update` treats an absent field as untouched
 * (TASK-080-008), so editing a title cannot clear a description.
 */
const props = defineProps<{
    taskId: string;
    field: 'title' | 'description';
    value: string | null;
    editable: boolean;
    multiline?: boolean;
    placeholder?: string;
}>();

const draft = ref(props.value ?? '');
const saving = ref(false);
const failed = ref(false);

// The server's value wins whenever it changes — unless this field is mid-edit, which is the
// one time the local text is the more recent truth.
watch(() => props.value, (value) => {
    if (!saving.value && !failed.value) {
        draft.value = value ?? '';
    }
});

const save = (): void => {
    const next = draft.value.trim();

    if (!props.editable || saving.value || next === (props.value ?? '').trim()) {
        return;
    }

    saving.value = true;

    router.put(
        TaskController.update.url(props.taskId),
        { [props.field]: props.field === 'description' && next === '' ? null : next },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                failed.value = false;
            },
            onError: () => {
                failed.value = true;
            },
            onFinish: () => {
                saving.value = false;
            },
        },
    );
};
</script>

<template>
    <div class="flex flex-col gap-1">
        <textarea
            v-if="multiline"
            v-model="draft"
            :disabled="!editable || saving"
            :placeholder="placeholder"
            rows="4"
            class="w-full rounded border border-input bg-transparent px-2 py-1 text-sm disabled:opacity-70"
            @blur="save"
            @keydown.enter.meta.prevent="save"
            @keydown.enter.ctrl.prevent="save"
        />

        <input
            v-else
            v-model="draft"
            type="text"
            :disabled="!editable || saving"
            :placeholder="placeholder"
            class="w-full rounded border border-transparent bg-transparent px-1 text-lg font-semibold hover:border-input disabled:opacity-70"
            @blur="save"
            @keydown.enter.prevent="save"
        />

        <p v-if="failed" class="text-xs text-destructive">
            Could not save. Your text is still here — try again.
            <button type="button" class="underline" @click="save">Retry</button>
        </p>
    </div>
</template>
