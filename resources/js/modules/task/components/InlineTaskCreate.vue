<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import ProjectTaskController from '@/actions/App/Http/Controllers/Project/ProjectTaskController';

/**
 * Add a task where you are looking. Focus stays in the input afterwards, because somebody
 * adding one task is usually adding three.
 */
const props = defineProps<{
    projectId: string;
    sectionId: string | null;
}>();

const open = ref(false);
const title = ref('');
const input = ref<HTMLInputElement | null>(null);
const saving = ref(false);

async function start(): Promise<void> {
    open.value = true;
    await nextTick();
    input.value?.focus();
}

function close(): void {
    open.value = false;
    title.value = '';
}

function submit(): void {
    if (title.value.trim() === '' || saving.value) {
        return;
    }

    saving.value = true;

    router.post(
        ProjectTaskController.store.url(props.projectId),
        { title: title.value, section: props.sectionId },
        {
            preserveScroll: true,
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
}
</script>

<template>
    <!-- From `md` the prompt starts where the task names start, so it reads as the next empty
         row of the column rather than as a control under it. -->
    <div class="px-4 py-2 md:pl-21">
        <button
            v-if="!open"
            type="button"
            data-add-task
            class="inline-flex min-h-11 items-center text-sm text-muted-foreground hover:text-foreground md:min-h-6"
            @click="start"
        >
            + Add task
        </button>

        <input
            v-else
            ref="input"
            v-model="title"
            type="text"
            placeholder="Task name"
            :disabled="saving"
            class="w-full rounded border border-input bg-transparent px-2 py-1 text-sm disabled:opacity-50"
            @keydown.enter.prevent="submit"
            @keydown.esc.prevent="close"
            @blur="title.trim() === '' ? close() : undefined"
        />
    </div>
</template>
