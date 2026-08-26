<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import SectionController from '@/actions/App/Http/Controllers/Section/SectionController';

/**
 * Add a column where the columns are.
 *
 * `SectionMenu` can already add one, but only from a column that exists — a project with one
 * column offered its second one from inside the first, and an empty board offered it nowhere at
 * all. This is the same endpoint with the affordance in the place somebody looks for it.
 *
 * Named on the way in, like a task is: a column called *New section* that has to be renamed
 * afterwards is two errands for one intention.
 */
const props = defineProps<{
    projectId: string;
    /** The board draws a column-shaped tile at the end of the row; the list, a row under it. */
    variant: 'board' | 'list';
}>();

const open = ref(false);
const name = ref('');
const input = ref<HTMLInputElement | null>(null);
const saving = ref(false);

async function start(): Promise<void> {
    open.value = true;
    await nextTick();
    input.value?.focus();
}

function close(): void {
    open.value = false;
    name.value = '';
}

function submit(): void {
    if (name.value.trim() === '' || saving.value) {
        return;
    }

    saving.value = true;

    router.post(
        SectionController.store.url(props.projectId),
        { name: name.value },
        {
            preserveScroll: true,
            // Focus stays here: somebody laying a board out is usually adding three columns, not
            // one — the same reason `InlineTaskCreate` keeps its input.
            onSuccess: async () => {
                name.value = '';
                await nextTick();
                input.value?.focus();
            },
            onFinish: () => (saving.value = false),
        },
    );
}
</script>

<template>
    <div :class="variant === 'board' ? 'w-full shrink-0 md:w-72' : 'px-4 py-2 md:px-6'">
        <button
            v-if="!open"
            type="button"
            data-add-section
            class="inline-flex items-center text-sm text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
            :class="
                variant === 'board'
                    ? 'min-h-11 w-full justify-center rounded-lg border border-dashed border-border hover:bg-accent/40'
                    : 'min-h-11 md:min-h-8'
            "
            @click="start"
        >
            + Add section
        </button>

        <input
            v-if="open"
            ref="input"
            v-model="name"
            type="text"
            placeholder="Section name"
            :disabled="saving"
            class="w-full rounded border border-input bg-transparent px-2 py-1 text-sm disabled:opacity-50"
            @keydown.enter.prevent="submit"
            @keydown.esc.prevent="close"
            @blur="name.trim() === '' ? close() : undefined"
        />
    </div>
</template>
