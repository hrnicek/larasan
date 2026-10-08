<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import TaskCustomFieldController from '@/actions/App/Http/Controllers/CustomField/TaskCustomFieldController';
import { inputTypeFor } from '@/modules/custom-field/fieldTypes';
import type { TaskCustomField } from '@/modules/task/types';

const props = defineProps<{
    taskId: string;
    fields: TaskCustomField[];
    editable: boolean;
}>();

const saving = ref<string | null>(null);
const failed = ref<string | null>(null);

const save = (
    field: TaskCustomField,
    value: string | number | boolean | null,
): void => {
    if (!props.editable) {
        return;
    }

    saving.value = field.id;

    router.put(
        TaskCustomFieldController.update.url({
            task: props.taskId,
            field: field.id,
        }),
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
    <p v-if="!fields.length" class="text-sm text-muted-foreground">
        No custom fields in these projects.
    </p>

    <dl
        v-else
        class="grid grid-cols-1 items-center gap-x-3 gap-y-1 md:grid-cols-[7.5rem_minmax(0,1fr)]"
    >
        <template v-for="field in fields" :key="field.id">
            <dt class="truncate text-[13px] text-muted-foreground">
                {{ field.name }}
            </dt>

            <dd class="flex min-h-8 flex-col justify-center">
                <select
                    v-if="field.type === 'select'"
                    :value="field.value ?? ''"
                    :disabled="!editable || saving === field.id"
                    class="h-8 w-full max-w-72 rounded-md border border-input bg-transparent px-2 text-sm disabled:opacity-70"
                    :aria-label="field.name"
                    @change="
                        save(
                            field,
                            ($event.target as HTMLSelectElement).value || null,
                        )
                    "
                >
                    <option value="">—</option>
                    <option
                        v-for="option in field.options"
                        :key="option.id"
                        :value="option.id"
                    >
                        {{ option.label }}
                    </option>
                </select>

                <input
                    v-else-if="field.type === 'boolean'"
                    type="checkbox"
                    :checked="field.value === true"
                    :disabled="!editable || saving === field.id"
                    class="size-4 rounded border border-input"
                    :aria-label="field.name"
                    @change="
                        save(field, ($event.target as HTMLInputElement).checked)
                    "
                />

                <input
                    v-else
                    :type="inputTypeFor(field.type)"
                    :value="field.value ?? ''"
                    :disabled="!editable || saving === field.id"
                    class="h-8 w-full max-w-72 rounded-md border border-transparent bg-transparent px-2 text-sm transition-colors hover:border-input focus:border-input focus:outline-none disabled:opacity-70"
                    :aria-label="field.name"
                    @change="onText(field, $event)"
                />

                <p v-if="failed === field.id" class="text-xs text-destructive">
                    Could not save that value.
                </p>
            </dd>
        </template>
    </dl>
</template>
