<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import PageTitleController from '@/actions/App/Http/Controllers/Page/PageTitleController';
import { UNTITLED } from '@/modules/page/lib/untitled';

// The title endpoint carries no version, so a rename never conflicts with a document autosave. See ADR-0017.
const props = defineProps<{
    pageId: string;
    title: string;
    editable: boolean;
}>();

const emit = defineEmits<{ done: [] }>();

const field = ref<HTMLInputElement | null>(null);
const draft = ref(props.title === UNTITLED ? '' : props.title);

let timer: ReturnType<typeof setTimeout> | null = null;

watch(
    () => props.pageId,
    () => (draft.value = props.title === UNTITLED ? '' : props.title),
);

const send = (): void => {
    if (draft.value.trim() === props.title.trim()) {
        return;
    }

    // A partial visit that preserves state, so the tree and tab update without rebuilding the editor.
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
