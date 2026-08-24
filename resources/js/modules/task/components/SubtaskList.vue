<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import TaskController from '@/actions/App/Http/Controllers/Task/TaskController';
import type { TaskDetail } from '@/modules/task/types';

/**
 * A task's children, and a way to add one.
 *
 * The depth limit is `CreateTask`'s to enforce (`ParentChain::MAX_DEPTH`), and this surfaces
 * its refusal rather than pre-empting it: a client that counted depth itself would be a second
 * copy of the rule, and the second copy is the one that drifts.
 */
const props = defineProps<{
    parentId: string;
    subtasks: TaskDetail['subtasks'];
    editable: boolean;
}>();

const open = ref(false);
const title = ref('');
const input = ref<HTMLInputElement | null>(null);
const saving = ref(false);

const start = async (): Promise<void> => {
    open.value = true;
    await nextTick();
    input.value?.focus();
};

const close = (): void => {
    open.value = false;
    title.value = '';
};

const submit = (): void => {
    if (title.value.trim() === '' || saving.value) {
        return;
    }

    saving.value = true;

    router.post(
        TaskController.store.url(),
        { title: title.value, parent_id: props.parentId },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: async () => {
                title.value = '';
                await nextTick();
                input.value?.focus();
            },
            onFinish: () => {
                saving.value = false;
            },
        },
    );
};
</script>

<template>
    <section>
        <h3 class="mb-1 text-xs text-muted-foreground">Subtasks</h3>

        <ul v-if="subtasks.length" class="flex flex-col gap-1 text-sm">
            <li v-for="subtask in subtasks" :key="subtask.id">
                <!-- A subtask opens the same detail view, because it is the same kind of thing. -->
                <Link
                    :href="`/tasks/${subtask.id}`"
                    :class="subtask.completedAt ? 'text-muted-foreground line-through' : ''"
                >
                    {{ subtask.title }}
                </Link>
            </li>
        </ul>

        <p v-else class="text-sm text-muted-foreground">No subtasks.</p>

        <div v-if="editable" class="mt-2">
            <button
                v-if="!open"
                type="button"
                class="inline-flex min-h-11 items-center md:min-h-6 text-xs text-muted-foreground hover:text-foreground"
                @click="start"
            >
                + Add subtask
            </button>

            <input
                v-else
                ref="input"
                v-model="title"
                type="text"
                placeholder="Subtask name"
                :disabled="saving"
                class="w-full rounded border border-input bg-transparent px-2 py-1 text-sm disabled:opacity-50"
                @keydown.enter.prevent="submit"
                @keydown.esc.prevent="close"
                @blur="title.trim() === '' ? close() : undefined"
            />
        </div>
    </section>
</template>
