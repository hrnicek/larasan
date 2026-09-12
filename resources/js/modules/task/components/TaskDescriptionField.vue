<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { defineAsyncComponent, ref, watch } from 'vue';
import TaskController from '@/actions/App/Http/Controllers/Task/TaskController';
import { Skeleton } from '@/components/ui/skeleton';

/** `v-html` is safe here only because `RichText` sanitizes descriptions to an allowlist on save. */
const props = defineProps<{
    taskId: string;
    value: string | null;
    editable: boolean;
    placeholder?: string;
}>();

const RichTextEditor = defineAsyncComponent(() => import('@/components/RichTextEditor.vue'));

const editing = ref(false);
const draft = ref(props.value ?? '');
const saving = ref(false);
const failed = ref(false);
const wrapper = ref<HTMLElement | null>(null);

watch(
    () => props.value,
    (value) => {
        if (!editing.value && !saving.value && !failed.value) {
            draft.value = value ?? '';
        }
    },
);

const start = (): void => {
    if (props.editable) {
        editing.value = true;
    }
};

/** Quill's empty document is `<p><br></p>`, so emptiness is judged on the text content. */
const blank = (html: string): boolean => html.replace(/<[^>]*>/g, '').trim() === '';

const save = (): void => {
    const next = blank(draft.value) ? null : draft.value;

    if (saving.value || next === (props.value ?? null)) {
        editing.value = false;

        return;
    }

    saving.value = true;

    router.put(
        TaskController.update.url(props.taskId),
        { description: next },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                failed.value = false;
                editing.value = false;
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

/** Saves once focus leaves the block; Quill's toolbar and link prompt are separate elements. */
const onFocusOut = (): void => {
    // Checked after focus settles; opening the editor moves focus in ways `relatedTarget` misreads.
    window.setTimeout(() => {
        if (!editing.value || wrapper.value?.contains(document.activeElement) === true) {
            return;
        }

        save();
    }, 0);
};
</script>

<template>
    <div ref="wrapper" class="flex flex-col gap-1" @focusout="onFocusOut">
        <div
            v-if="editing"
            class="flex flex-col-reverse rounded-lg border border-input transition-colors focus-within:border-ring"
        >
            <Suspense>
                <RichTextEditor v-model="draft" :placeholder="placeholder" />

                <template #fallback>
                    <div class="flex flex-col gap-2 p-2" aria-hidden="true">
                        <Skeleton class="h-4 w-2/3 animate-pulse" />
                        <Skeleton class="h-4 w-1/2 animate-pulse" />
                    </div>
                </template>
            </Suspense>
        </div>

        <button
            v-else-if="editable"
            type="button"
            class="w-full cursor-text rounded-lg border border-transparent px-2 py-1.5 text-left transition-colors hover:border-input focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
            :aria-label="value ? 'Edit the description' : 'Add a description'"
            @click="start"
        >
            <div v-if="value" class="rich-text" v-html="value" />
            <span v-else class="text-sm text-muted-foreground">{{ placeholder }}</span>
        </button>

        <div v-else class="px-2 py-1.5">
            <div v-if="value" class="rich-text" v-html="value" />
            <span v-else class="text-sm text-muted-foreground">No description.</span>
        </div>

        <p v-if="failed" class="text-xs text-destructive">
            Could not save. Your text is still here — try again.
            <button type="button" class="underline" @click="save">Retry</button>
        </p>
    </div>
</template>
