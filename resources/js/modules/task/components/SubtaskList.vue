<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronRight, CircleCheck, Plus } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import TaskController from '@/actions/App/Http/Controllers/Task/TaskController';
import { Button } from '@/components/ui/button';
import TaskSectionHeading from '@/modules/task/components/TaskSectionHeading.vue';
import type { TaskDetail } from '@/modules/task/types';

const props = defineProps<{
    parentId: string;
    subtasks: TaskDetail['subtasks'];
    editable: boolean;
}>();

const emit = defineEmits<{ open: [taskId: string] }>();

const done = computed<number>(() => props.subtasks.filter((subtask) => subtask.completedAt !== null).length);

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

const toggle = (subtask: TaskDetail['subtasks'][number]): void => {
    if (!props.editable) {
        return;
    }

    if (subtask.completedAt !== null) {
        router.delete(TaskController.reopen.url(subtask.id), { preserveScroll: true, preserveState: true });

        return;
    }

    router.put(TaskController.complete.url(subtask.id), {}, { preserveScroll: true, preserveState: true });
};
</script>

<template>
    <section class="flex flex-col gap-1">
        <TaskSectionHeading title="Subtasks" :count="subtasks.length ? `${done} / ${subtasks.length}` : null">
            <template v-if="editable" #add>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    class="size-7 text-muted-foreground"
                    aria-label="Add a subtask"
                    @click="start"
                >
                    <Plus class="size-4" />
                </Button>
            </template>
        </TaskSectionHeading>

        <ul v-if="subtasks.length" class="flex flex-col divide-y divide-border border-y border-border">
            <li
                v-for="subtask in subtasks"
                :key="subtask.id"
                class="group/subtask flex items-center gap-2.5 py-1.5 pr-1 transition-colors hover:bg-accent/40"
            >
                <button
                    type="button"
                    class="inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-md transition-colors focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none disabled:cursor-not-allowed md:size-6"
                    :class="
                        subtask.completedAt
                            ? 'text-emerald-600 dark:text-emerald-400'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    :disabled="!editable"
                    :aria-pressed="subtask.completedAt !== null"
                    :aria-label="subtask.completedAt ? `Reopen ${subtask.title}` : `Complete ${subtask.title}`"
                    @click="toggle(subtask)"
                >
                    <CircleCheck class="size-4" />
                </button>

                <button
                    type="button"
                    class="min-h-11 min-w-0 flex-1 truncate text-left text-sm transition-colors hover:text-primary focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none md:min-h-6"
                    :class="subtask.completedAt ? 'text-muted-foreground line-through' : ''"
                    @click="emit('open', subtask.id)"
                >
                    {{ subtask.title }}
                </button>

                <ChevronRight
                    class="size-4 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover/subtask:opacity-100"
                    aria-hidden="true"
                />
            </li>
        </ul>

        <div v-if="editable">
            <input
                v-if="open"
                ref="input"
                v-model="title"
                type="text"
                placeholder="Subtask name"
                :disabled="saving"
                class="w-full rounded-md border border-input bg-transparent px-2 py-1.5 text-sm disabled:opacity-50"
                @keydown.enter.prevent="submit"
                @keydown.esc.prevent="close"
                @blur="title.trim() === '' ? close() : undefined"
            />

            <button
                v-else
                type="button"
                class="inline-flex min-h-11 items-center gap-1.5 rounded-md px-1 text-sm text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none md:min-h-8"
                @click="start"
            >
                <Plus class="size-4" aria-hidden="true" />
                Add subtask
            </button>
        </div>

        <p v-else-if="!subtasks.length" class="text-sm text-muted-foreground">No subtasks.</p>
    </section>
</template>
