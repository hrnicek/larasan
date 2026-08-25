<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import PageTitleController from '@/actions/App/Http/Controllers/Page/PageTitleController';
import { UNTITLED } from '@/modules/page/lib/untitled';

/**
 * A page's title, typed where it is read.
 *
 * A field rather than a heading with a pencil beside it: renaming a document is the most ordinary
 * thing somebody does to one, and a dialog for it is a dialog in the way. The heading structure
 * survives because the field is inside the `h1` — `input` is phrasing content, so the outline
 * still says what this page is called.
 *
 * Saved through `pages.title.update`, which carries no version: a rename touches no word anybody
 * wrote, so it must not refuse a colleague's next autosave (ADR-0017).
 */
const props = defineProps<{
    pageId: string;
    title: string;
    editable: boolean;
}>();

const emit = defineEmits<{ done: [] }>();

const field = ref<HTMLInputElement | null>(null);
const draft = ref(props.title === UNTITLED ? '' : props.title);

let timer: ReturnType<typeof setTimeout> | null = null;

/** A different page arrived under the same component. */
watch(
    () => props.pageId,
    () => (draft.value = props.title === UNTITLED ? '' : props.title),
);

const send = (): void => {
    if (draft.value.trim() === props.title.trim()) {
        return;
    }

    /*
     * A visit rather than a raw request: the title appears in the tree beside the document and in
     * the browser's own tab, and the server is what decides what an empty title is called. The
     * visit is partial and preserves state, so the editor underneath is not rebuilt and the caret
     * in it survives a rename.
     */
    router.put(
        PageTitleController.update.url(props.pageId),
        { title: draft.value },
        { preserveScroll: true, preserveState: true, only: ['page', 'pages'] },
    );
};

const schedule = (): void => {
    if (timer !== null) {
        clearTimeout(timer);
    }

    timer = setTimeout(send, 700);
};

const flush = (): void => {
    if (timer !== null) {
        clearTimeout(timer);
        timer = null;
    }

    send();
};

/** Enter is not submit here: it is the end of the title and the start of the document. */
const leave = (): void => {
    flush();
    emit('done');
};

const restore = (): void => {
    draft.value = props.title === UNTITLED ? '' : props.title;

    if (timer !== null) {
        clearTimeout(timer);
        timer = null;
    }

    field.value?.blur();
};

onBeforeUnmount(() => {
    if (timer !== null) {
        clearTimeout(timer);
    }
});
</script>

<template>
    <h1 class="pb-3">
        <input
            v-if="editable"
            ref="field"
            v-model="draft"
            type="text"
            :placeholder="UNTITLED"
            aria-label="Page title"
            maxlength="255"
            class="w-full border-0 bg-transparent p-0 text-2xl font-semibold tracking-tight text-foreground placeholder:text-muted-foreground/60 focus:outline-none"
            @input="schedule"
            @blur="flush"
            @keydown.enter.prevent="leave"
            @keydown.esc.prevent="restore"
        />

        <span v-else class="block text-2xl font-semibold tracking-tight text-foreground">{{ title }}</span>
    </h1>
</template>
