<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import ProjectTaskController from '@/actions/App/Http/Controllers/Project/ProjectTaskController';

/**
 * Add a task where you are looking. Focus stays in the input afterwards, because somebody
 * adding one task is usually adding three.
 */
const props = withDefaults(
    defineProps<{
        projectId: string;
        sectionId: string | null;
        /**
         * The day the new task is due, when it is being added somewhere that means one — a
         * calendar cell is a date, so typing a title into it schedules the task as well.
         */
        dueAt?: string | null;
        /** A day cell has no room for the list's left margin, and says "Add" rather than "Add task". */
        compact?: boolean;
    }>(),
    { dueAt: null, compact: false },
);

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
        { title: title.value, section: props.sectionId, due_at: props.dueAt },
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
    <div :class="compact ? '' : 'px-4 py-2 md:pl-21'">
        <button
            v-if="!open"
            type="button"
            data-add-task
            class="inline-flex items-center text-sm text-muted-foreground hover:text-foreground"
            :class="compact ? 'min-h-6 w-full rounded px-1.5 text-xs hover:bg-accent' : 'min-h-11 md:min-h-6'"
            @click="start"
        >
            {{ compact ? '+ Add' : '+ Add task' }}
        </button>

        <input
            v-else
            ref="input"
            v-model="title"
            type="text"
            placeholder="Task name"
            :disabled="saving"
            class="w-full rounded border border-input bg-transparent disabled:opacity-50"
            :class="compact ? 'px-1.5 py-0.5 text-xs' : 'px-2 py-1 text-sm'"
            @keydown.enter.prevent="submit"
            @keydown.esc.prevent="close"
            @blur="title.trim() === '' ? close() : undefined"
        />
    </div>
</template>
