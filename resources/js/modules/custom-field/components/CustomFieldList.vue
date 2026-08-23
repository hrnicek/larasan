<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import TaskCustomFieldController from '@/actions/App/Http/Controllers/CustomField/TaskCustomFieldController';
import type { TaskCustomField } from '@/modules/task/types';

/**
 * The fields this task's projects record, and what this task has answered.
 *
 * Each type gets the control it deserves rather than a text box with a promise: a date opens a
 * date picker, a choice offers the choices, and a number refuses letters before the request is
 * ever made. What is written is still the server's decision — these controls only ask.
 */
const props = defineProps<{
    taskId: string;
    fields: TaskCustomField[];
    editable: boolean;
}>();

/** Which field is mid-save, so a slow network cannot be mistaken for a lost keystroke. */
const saving = ref<string | null>(null);
const failed = ref<string | null>(null);

const save = (field: TaskCustomField, value: string | number | boolean | null): void => {
    if (!props.editable) {
        return;
    }

    saving.value = field.id;

    router.put(
        TaskCustomFieldController.update.url({ task: props.taskId, field: field.id }),
        { value },
        {
            preserveScroll: true,
            onSuccess: () => {
                failed.value = null;
            },
            onError: () => {
                failed.value = field.id;
            },
            onFinish: () => {
                saving.value = null;
            },
        },
    );
};

const onText = (field: TaskCustomField, event: Event): void => {
    const value = (event.target as HTMLInputElement).value;

    save(field, value === '' ? null : value);
};
</script>

<template>
    <section v-if="fields.length" class="flex flex-col gap-2">
        <h3 class="text-xs text-muted-foreground">Fields</h3>

        <dl class="flex flex-col gap-2">
            <div v-for="field in fields" :key="field.id" class="flex flex-col gap-1">
                <dt class="text-xs text-muted-foreground">{{ field.name }}</dt>

                <dd>
                    <select
                        v-if="field.type === 'select'"
                        :value="field.value ?? ''"
                        :disabled="!editable || saving === field.id"
                        class="w-full rounded border border-input bg-transparent px-2 py-1 text-sm disabled:opacity-70"
                        @change="save(field, ($event.target as HTMLSelectElement).value || null)"
                    >
                        <option value="">—</option>
                        <option v-for="option in field.options" :key="option.id" :value="option.id">
                            {{ option.label }}
                        </option>
                    </select>

                    <input
                        v-else-if="field.type === 'boolean'"
                        type="checkbox"
                        :checked="field.value === true"
                        :disabled="!editable || saving === field.id"
                        class="size-4 rounded border border-input"
                        @change="save(field, ($event.target as HTMLInputElement).checked)"
                    />

                    <input
                        v-else
                        :type="field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'"
                        :value="field.value ?? ''"
                        :disabled="!editable || saving === field.id"
                        class="w-full rounded border border-input bg-transparent px-2 py-1 text-sm disabled:opacity-70"
                        @change="onText(field, $event)"
                    />

                    <p v-if="failed === field.id" class="text-xs text-destructive">
                        Could not save that value.
                    </p>
                </dd>
            </div>
        </dl>
    </section>
</template>
