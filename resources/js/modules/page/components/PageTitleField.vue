<script setup lang="ts">
import { http, router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import PageTitleController from '@/actions/App/Http/Controllers/Page/PageTitleController';
import { UNTITLED } from '@/modules/page/lib/untitled';

// The title endpoint carries no version, so a rename never conflicts with a document autosave. See ADR-0017.
const props = defineProps<{
    pageId: string;
    title: string;
    editable: boolean;
}>();

const emit = defineEmits<{ done: [] }>();

const shown = (title: string): string => (title === UNTITLED ? '' : title);

const field = ref<HTMLInputElement | null>(null);
const draft = ref(shown(props.title));

let sent = draft.value;
let sending = false;
let mounted = true;
let timer: ReturnType<typeof setTimeout> | null = null;

watch(
    () => props.pageId,
    () => {
        draft.value = shown(props.title);
        sent = draft.value;
    },
);

const stopTimer = (): void => {
    if (timer !== null) {
        clearTimeout(timer);
        timer = null;
    }
};

const refusalOf = (failure: unknown): string | null => {
    const body = (failure as { response?: { data?: unknown } })?.response?.data;

    try {
        const message =
            typeof body === 'string'
                ? (JSON.parse(body) as { message?: unknown }).message
                : null;

        return typeof message === 'string' ? message : null;
    } catch {
        return null;
    }
};

const send = async (): Promise<void> => {
    const title = draft.value;

    if (sending || title.trim() === sent.trim()) {
        return;
    }

    const previous = sent;

    sending = true;
    sent = title;

    try {
        // Not a visit: the next visit cancels one in flight, and this save must outlive leaving the page.
        await http.getClient().request({
            method: 'put',
            url: PageTitleController.update.url(props.pageId),
            data: { title },
            headers: { Accept: 'application/json' },
        });

        if (mounted) {
            router.reload({ only: ['page', 'pages'] });
        }
    } catch (failure: unknown) {
        sent = previous;
        toast.error(refusalOf(failure) ?? 'The page title could not be saved.');
    } finally {
        sending = false;
    }

    if (timer === null && draft.value.trim() !== title.trim()) {
        void send();
    }
};

const schedule = (): void => {
    stopTimer();

    timer = setTimeout(() => {
        timer = null;
        void send();
    }, 700);
};

const flush = (): void => {
    stopTimer();
    void send();
};

const leave = (): void => {
    flush();
    emit('done');
};

const restore = (): void => {
    stopTimer();
    draft.value = sent;
    field.value?.blur();
};

// Partial reloads stay on this page, so they leave a pending title to its quiet period.
const stopListening = router.on('before', ({ detail: { visit } }) => {
    if (
        !visit.prefetch &&
        visit.only.length === 0 &&
        visit.except.length === 0
    ) {
        flush();
    }
});

onBeforeUnmount(() => {
    mounted = false;
    stopListening();
    flush();
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

        <span
            v-else
            class="block text-2xl font-semibold tracking-tight text-foreground"
            >{{ title }}</span
        >
    </h1>
</template>
