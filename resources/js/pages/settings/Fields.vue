<script setup lang="ts">
import { Form, Head, router, useForm } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import CustomFieldController from '@/actions/App/Http/Controllers/CustomField/CustomFieldController';
import CustomFieldOptionController from '@/actions/App/Http/Controllers/CustomField/CustomFieldOptionController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { typeHints, typeLabels } from '@/modules/custom-field/fieldTypes';
import type { CustomFieldType, WorkspaceCustomField } from '@/modules/custom-field/types';

const props = defineProps<{
    fields: WorkspaceCustomField[];
    types: CustomFieldType[];
    can: { manage: boolean };
}>();

const form = useForm<{ name: string; type: CustomFieldType; options: string[] }>({
    name: '',
    type: 'text',
    options: [''],
});

const isChoice = computed(() => form.type === 'select');

// Array errors arrive keyed by index (`options.0`), which the typed form errors do not model.
const optionError = (index: number): string | undefined =>
    (form.errors as Record<string, string | undefined>)[`options.${index}`];

const choiceInputs = ref<HTMLInputElement[]>([]);

function addChoice(): void {
    form.options.push('');

    void nextTick(() => choiceInputs.value[form.options.length - 1]?.focus());
}

function removeChoice(index: number): void {
    form.options.splice(index, 1);

    if (form.options.length === 0) {
        form.options.push('');
    }
}

function submit(): void {
    form
        // The server reads an absent `options` key as "none" but an empty array as an emptied choice field.
        .transform((data) => (data.type === 'select' ? data : { name: data.name, type: data.type }))
        .post(CustomFieldController.store.url(), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                form.options = [''];
            },
        });
}

const renaming = ref<string | null>(null);
const deleting = ref<WorkspaceCustomField | null>(null);

const deletionCost = computed(() => {
    const field = deleting.value;

    if (field === null) {
        return '';
    }

    const answers =
        field.valueCount === 1 ? 'one answer' : `${field.valueCount} answers`;
    const projects =
        field.projectCount === 1 ? 'one project' : `${field.projectCount} projects`;

    if (field.valueCount === 0 && field.projectCount === 0) {
        return 'Nothing has been recorded in it yet.';
    }

    return `It is shown on ${projects}, and ${answers} recorded in it are deleted with it. Taking a field off one project instead keeps them.`;
});

function confirmDeletion(): void {
    if (deleting.value === null) {
        return;
    }

    router.delete(CustomFieldController.destroy.url(deleting.value.id), {
        preserveScroll: true,
        onFinish: () => (deleting.value = null),
    });
}

// Sent whole: entries with an id are kept, entries without one are created, omitted ids are removed.
const editingChoices = ref<string | null>(null);

const choices = useForm<{ options: { id: string | null; label: string }[] }>({ options: [] });

function editChoices(field: WorkspaceCustomField): void {
    editingChoices.value = field.id;
    choices.clearErrors();
    choices.options = field.options.map((option) => ({ id: option.id, label: option.label }));
}

function choiceError(index: number): string | undefined {
    return (choices.errors as Record<string, string | undefined>)[`options.${index}.label`];
}

function saveChoices(fieldId: string): void {
    choices.put(CustomFieldOptionController.update.url(fieldId), {
        preserveScroll: true,
        onSuccess: () => (editingChoices.value = null),
    });
}

function summary(field: WorkspaceCustomField): string {
    if (field.type !== 'select') {
        return typeLabels[field.type];
    }

    return `${typeLabels.select} · ${field.options.map((option) => option.label).join(', ')}`;
}
</script>

<template>
    <div class="flex flex-col space-y-6">
        <Head title="Fields" />

        <h1 class="sr-only">Custom fields</h1>

        <Heading
            variant="small"
            title="Fields"
            description="What this workspace records about its work, beyond a title and a due date"
        />

        <form
            v-if="props.can.manage"
            class="space-y-4 rounded-lg border p-4"
            @submit.prevent="submit"
        >
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end">
                <div class="grid flex-1 gap-2">
                    <Label for="field-name">Name</Label>
                    <Input id="field-name" v-model="form.name" required placeholder="Estimate" />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="field-type">Type</Label>
                    <select
                        id="field-type"
                        v-model="form.type"
                        class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    >
                        <option v-for="type in props.types" :key="type" :value="type">
                            {{ typeLabels[type] }}
                        </option>
                    </select>
                    <InputError :message="form.errors.type" />
                </div>

                <Button type="submit" :disabled="form.processing">Add field</Button>
            </div>

            <p class="text-muted-foreground text-xs">{{ typeHints[form.type] }}</p>

            <fieldset v-if="isChoice" class="space-y-2 border-t pt-4">
                <legend class="sr-only">Choices</legend>

                <div v-for="(_, index) in form.options" :key="index" class="flex items-start gap-2">
                    <div class="flex-1">
                        <Label :for="`choice-${index}`" class="sr-only">Choice {{ index + 1 }}</Label>
                        <Input
                            :id="`choice-${index}`"
                            ref="choiceInputs"
                            v-model="form.options[index]"
                            :placeholder="`Choice ${index + 1}`"
                        />
                        <InputError :message="optionError(index)" />
                    </div>

                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        :aria-label="`Remove choice ${index + 1}`"
                        @click="removeChoice(index)"
                    >
                        <X class="size-4" />
                    </Button>
                </div>

                <InputError :message="form.errors.options" />

                <Button type="button" variant="ghost" size="sm" @click="addChoice">
                    <Plus class="size-4" />
                    Add choice
                </Button>
            </fieldset>
        </form>

        <p v-if="!props.fields.length" class="text-muted-foreground rounded-lg border border-dashed p-6 text-sm">
            No fields yet. A field is a column every project can choose to show — an estimate, a
            client, a stage of its own.
        </p>

        <ul v-else class="divide-y rounded-lg border">
            <li v-for="field in props.fields" :key="field.id" class="space-y-4 p-4">
                <div class="flex flex-wrap items-center gap-3">
                <Form
                    v-if="renaming === field.id"
                    v-bind="CustomFieldController.update.form(field.id)"
                    class="flex flex-1 items-center gap-2"
                    :options="{ preserveScroll: true }"
                    @success="renaming = null"
                    v-slot="{ errors, processing }"
                >
                    <div class="flex-1">
                        <Label :for="`name-${field.id}`" class="sr-only">Field name</Label>
                        <Input :id="`name-${field.id}`" name="name" :default-value="field.name" autofocus />
                        <InputError :message="errors.name" />
                    </div>
                    <Button type="submit" size="sm" :disabled="processing">Save</Button>
                    <Button type="button" size="sm" variant="ghost" @click="renaming = null">Cancel</Button>
                </Form>

                <template v-else>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium">{{ field.name }}</p>
                        <p class="text-muted-foreground truncate text-xs">{{ summary(field) }}</p>
                    </div>

                    <span class="text-muted-foreground shrink-0 text-xs">
                        {{ field.projectCount === 1 ? 'on 1 project' : `on ${field.projectCount} projects` }}
                    </span>

                    <div v-if="props.can.manage" class="flex shrink-0 items-center gap-0.5">
                        <Button
                            v-if="field.type === 'select'"
                            size="sm"
                            variant="ghost"
                            @click="editChoices(field)"
                        >
                            Choices
                        </Button>
                        <Button size="sm" variant="ghost" @click="renaming = field.id">Rename</Button>
                        <Button size="sm" variant="ghost" @click="deleting = field">Delete</Button>
                    </div>
                </template>
                </div>

                <div v-if="editingChoices === field.id" class="space-y-2 border-t pt-4">
                    <div
                        v-for="(choice, index) in choices.options"
                        :key="choice.id ?? `new-${index}`"
                        class="flex items-start gap-2"
                    >
                        <div class="flex-1">
                            <Label :for="`option-${field.id}-${index}`" class="sr-only">Choice {{ index + 1 }}</Label>
                            <Input
                                :id="`option-${field.id}-${index}`"
                                v-model="choices.options[index].label"
                                :placeholder="`Choice ${index + 1}`"
                            />
                            <InputError :message="choiceError(index)" />
                        </div>

                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            :aria-label="`Remove choice ${index + 1}`"
                            @click="choices.options.splice(index, 1)"
                        >
                            <X class="size-4" />
                        </Button>
                    </div>

                    <InputError :message="choices.errors.options" />

                    <div class="flex flex-wrap items-center gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="choices.options.push({ id: null, label: '' })"
                        >
                            <Plus class="size-4" />
                            Add choice
                        </Button>

                        <span class="flex-1"></span>

                        <Button size="sm" :disabled="choices.processing" @click="saveChoices(field.id)">
                            Save choices
                        </Button>
                        <Button size="sm" variant="ghost" @click="editingChoices = null">Cancel</Button>
                    </div>

                    <p class="text-muted-foreground text-xs">
                        Removing a choice empties the answers that picked it. Renaming one keeps them.
                    </p>
                </div>
            </li>
        </ul>

        <ConfirmDialog
            :open="deleting !== null"
            :title="`Delete ${deleting?.name}?`"
            :description="deletionCost"
            confirm-label="Delete field"
            cancel-label="Keep it"
            @update:open="(next) => !next && (deleting = null)"
            @confirm="confirmDeletion"
        />
    </div>
</template>
