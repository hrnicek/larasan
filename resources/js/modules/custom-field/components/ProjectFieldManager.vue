<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import { ref } from 'vue';
import ProjectCustomFieldController from '@/actions/App/Http/Controllers/CustomField/ProjectCustomFieldController';
import { Button } from '@/components/ui/button';
import type { CustomFieldType } from '@/modules/custom-field/types';
import { index as workspaceFields } from '@/routes/custom-fields';

/**
 * Which of the workspace's fields this project shows.
 *
 * Attaching and detaching, and nothing else: what a field *is* — its name, its type, its choices —
 * is a workspace decision made on `/settings/fields`, and offering half of it here would be two
 * screens disagreeing about the same object.
 */
const props = defineProps<{
    projectId: string;
    attached: { id: string; name: string; type: CustomFieldType }[];
    available: { id: string; name: string; type: CustomFieldType }[];
    canManage: boolean;
}>();

const typeLabels: Record<CustomFieldType, string> = {
    text: 'Text',
    number: 'Number',
    date: 'Date',
    boolean: 'Yes / no',
    select: 'Choice',
};

/**
 * A write finished. The settings screen has no use for it — its props come back with the redirect
 * — but the *Customize* drawer's list is `Inertia::optional` and is not in that response, so it
 * asks for its own again.
 */
const emit = defineEmits<{ changed: [] }>();

/** Which row is mid-request, so a slow network is not mistaken for a control that did nothing. */
const pending = ref<string | null>(null);

function attach(id: string): void {
    pending.value = id;

    router.post(
        ProjectCustomFieldController.store.url(props.projectId),
        { field: id },
        {
            preserveScroll: true,
            onSuccess: () => emit('changed'),
            onFinish: () => (pending.value = null),
        },
    );
}

/*
 * Not confirmed, and deliberately: taking a column off a board keeps every answer, so putting the
 * field back brings them with it. Deleting the field is the operation that does not, and that one
 * asks (ADR-0013 — a modal never holds an unconfirmed destructive operation).
 */
function detach(id: string): void {
    pending.value = id;

    router.delete(ProjectCustomFieldController.destroy.url({ project: props.projectId, field: id }), {
        preserveScroll: true,
        onSuccess: () => emit('changed'),
        onFinish: () => (pending.value = null),
    });
}
</script>

<template>
    <div class="space-y-4">
        <ul v-if="props.attached.length" class="divide-y rounded-lg border">
            <li v-for="field in props.attached" :key="field.id" class="flex items-center gap-3 px-3 py-2">
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium">{{ field.name }}</p>
                    <p class="text-muted-foreground text-xs">{{ typeLabels[field.type] }}</p>
                </div>

                <Button
                    v-if="props.canManage"
                    variant="ghost"
                    size="icon-sm"
                    :disabled="pending === field.id"
                    :aria-label="`Remove ${field.name} from this project`"
                    @click="detach(field.id)"
                >
                    <X class="size-4" />
                </Button>
            </li>
        </ul>

        <p v-else class="text-muted-foreground text-sm">
            This project shows no custom fields. Its tasks still have a title, an assignee and a
            due date.
        </p>

        <template v-if="props.canManage">
            <div v-if="props.available.length" class="flex flex-wrap gap-2">
                <Button
                    v-for="field in props.available"
                    :key="field.id"
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="pending === field.id"
                    @click="attach(field.id)"
                >
                    <Plus class="size-4" />
                    {{ field.name }}
                </Button>
            </div>

            <p class="text-muted-foreground text-xs">
                Removing a field here keeps every answer already recorded in it — putting it back
                brings them with it.
                <!-- The link is where fields are made, so an empty picker is not a dead end. -->
                <a :href="workspaceFields.url()" class="underline underline-offset-2">
                    Fields are defined in workspace settings.
                </a>
            </p>
        </template>
    </div>
</template>
