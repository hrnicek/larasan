<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import TaskController from '@/actions/App/Http/Controllers/Task/TaskController';

/** Sends only `title`; `tasks.update` leaves absent fields untouched. */
const props = defineProps<{
    taskId: string;
    value: string | null;
    editable: boolean;
    placeholder?: string;
    size?: 'title' | 'row';
}>();

const draft = ref(props.value ?? '');
const saving = ref(false);
const failed = ref(false);

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
            An input cannot size itself to its value, so a hidden twin measures the text in the
            same grid cell. It copies the input's border and padding, or the last letter is clipped.
        -->
        <div class="grid min-w-0 max-w-full">
            <span
                v-if="size === 'row'"
                class="invisible col-start-1 row-start-1 min-w-8 truncate border border-transparent px-1 text-sm whitespace-pre"
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
