<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { defineAsyncComponent, ref, watch } from 'vue';
import TaskController from '@/actions/App/Http/Controllers/Task/TaskController';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * What a task is about, written where it is read.
 *
 * Rich text, so two things are true that were not true of the plain field this replaces. The
 * editor is loaded only when somebody starts writing — it is a hundred kilobytes nobody reading a
 * list of tasks needs — and what comes back is **markup**, which is rendered here only because
 * `RichText` has already reduced it to an allowlist on the way in. Nothing else in this
 * application draws `v-html`, and nothing else should without that guarantee.
 *
 * The rule that matters is still the failure one `TaskTextField` established: **a failed save
 * never discards what was typed.** The field keeps the text, says so, and stays open.
 */
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

// The server's value wins whenever it changes — unless this field is being written in, which is
// the one time the local text is the more recent truth.
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

/**
 * Quill's empty document is `<p><br></p>`, so "unchanged" cannot be decided by comparing strings
 * with the stored value — which is `null` when nothing was ever written.
 */
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

/**
 * Saved when the writing is over rather than on a button, which is what the reference does. The
 * check is whether focus left the block at all: Quill's toolbar and its link prompt are separate
 * elements, and treating a click on *bold* as leaving the field would close the editor every time
 * somebody used it.
 */
const onFocusOut = (): void => {
    /*
     * Asked after the browser has settled rather than during the event. Two things move focus
     * through the document on the way *in*: the control that opened the editor is replaced by
     * it, and the editor itself arrives a chunk-load later — both look like leaving if the
     * question is asked from `relatedTarget`.
     */
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
        <!--
            Reversed, so the toolbar is drawn under the words the way the reference has it. Only
            the drawing is reversed: Quill emits the toolbar first, and the editor is focused as
            soon as it is ready, so the caret starts in the text either way.
        -->
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

        <!--
            Read mode is a button rather than a div with a click handler: it is the control that
            opens the editor, and a keyboard has to be able to reach it.
        -->
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
