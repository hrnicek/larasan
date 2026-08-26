<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import TaskController from '@/actions/App/Http/Controllers/Task/TaskController';

/**
 * A task's title, edited where it is read — as the task's own heading, and inside a list row.
 *
 * The description left this component when it became rich text (`TaskDescriptionField`): an
 * allowlist, an editor loaded on demand and markup on the way back are three concerns a plain
 * text field has none of.
 *
 * The rule that matters is the failure one: **a failed save never discards what was typed.**
 * The field keeps the text, says so, and offers to try again — an editor that throws away a
 * paragraph because the network blinked is one people copy out of before using.
 *
 * Only the title is sent. `tasks.update` treats an absent field as untouched
 * (TASK-080-008), so renaming a task cannot clear its description.
 */
const props = defineProps<{
    taskId: string;
    value: string | null;
    editable: boolean;
    placeholder?: string;
    /**
     * `title` is the task's own heading in the panel and on its page; `row` is the same field
     * inside a list line, where it has to sit at the row's weight and not look like a form.
     */
    size?: 'title' | 'row';
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
        { title: next },
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
    <div class="flex flex-col gap-1" :class="size === 'row' ? 'min-w-24 shrink @lg:min-w-32' : 'min-w-0'">
        <!--
            In a row the field is only as wide as what is written in it: the rest of the cell
            belongs to the row, which opens the task. An input will not size itself to its value,
            so a hidden twin of the text does the measuring and both share one grid cell.

            The floor is the other half of that rule. What is beside the field in a row — the
            comment count, the button that opens the task — used to be able to take every pixel of
            it, and a title measured at zero is a row with no name on it. So the field is the one
            thing in the cell that gives way, and it gives way down to a floor: 6rem in a narrow
            name cell, 8rem once that cell is wide enough to afford it — the cell is the container
            these widths answer to, because a project's field columns decide how much of the window
            the name ever sees. An input cannot draw an ellipsis, so what it had to cut is in the
            tooltip.
        -->
        <div class="grid min-w-0 max-w-full">
            <span
                v-if="size === 'row'"
                class="invisible col-start-1 row-start-1 min-w-8 truncate px-1 text-sm whitespace-pre"
                aria-hidden="true"
            >
                {{ draft || placeholder || ' ' }}
            </span>

            <input
                v-model="draft"
                type="text"
                :disabled="!editable || saving"
                :placeholder="placeholder"
                :title="size === 'row' ? draft : undefined"
                class="col-start-1 row-start-1 w-full rounded-md border border-transparent bg-transparent hover:border-input focus:border-input focus:outline-none disabled:opacity-70"
                :class="
                    size === 'row'
                        ? 'min-h-11 px-1 text-sm md:min-h-6'
                        : 'px-1.5 py-1 text-2xl leading-tight font-semibold tracking-tight'
                "
                @blur="save"
                @keydown.enter.prevent="save"
            />
        </div>

        <p v-if="failed" class="text-xs text-destructive">
            Could not save. Your text is still here — try again.
            <button type="button" class="underline" @click="save">Retry</button>
        </p>
    </div>
</template>
