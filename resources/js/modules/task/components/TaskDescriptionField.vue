<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, Loader2, Pencil } from '@lucide/vue';
import {
    computed,
    defineAsyncComponent,
    onBeforeUnmount,
    ref,
    watch,
} from 'vue';
import TaskController from '@/actions/App/Http/Controllers/Task/TaskController';
import { Skeleton } from '@/components/ui/skeleton';

/** Descriptions are sanitized by RichText before storage; this is the only user-authored HTML rendered here. */
const props = defineProps<{
    taskId: string;
    value: string | null;
    editable: boolean;
    placeholder?: string;
}>();

const emit = defineEmits<{ pendingChange: [pending: boolean] }>();

const RichTextEditor = defineAsyncComponent(
    () => import('@/components/RichTextEditor.vue'),
);

const editing = ref(false);
const draft = ref(props.value ?? '');
const savedValue = ref<string | null>(props.value);
const saving = ref(false);
const saved = ref(false);
const error = ref<string | null>(null);
const conflict = ref(false);
const wrapper = ref<HTMLElement | null>(null);
let focusTimer: ReturnType<typeof setTimeout> | null = null;

const nextValue = computed<string | null>(() =>
    draft.value.replace(/<[^>]*>/g, '').trim() === '' ? null : draft.value,
);
const dirty = computed<boolean>(() => nextValue.value !== savedValue.value);
const pending = computed<boolean>(() => dirty.value || saving.value);

watch(pending, (value) => emit('pendingChange', value), { immediate: true });

watch(
    () => props.value,
    (value) => {
        if (!editing.value && !saving.value && error.value === null) {
            savedValue.value = value;
            draft.value = value ?? '';
        }
    },
);

const rendered = computed<string>(() => {
    if (!props.value) {
        return '';
    }

    const parsed = new DOMParser().parseFromString(props.value, 'text/html');

    parsed.querySelectorAll('a[href]').forEach((link) => {
        link.setAttribute('target', '_blank');
        link.setAttribute('rel', 'noopener noreferrer');
    });

    return parsed.body.innerHTML;
});

const start = (): void => {
    if (props.editable) {
        saved.value = false;
        editing.value = true;
    }
};

const startUnlessReading = (event: MouseEvent): void => {
    const onLink =
        event.target instanceof Element && event.target.closest('a') !== null;
    const selecting = window.getSelection()?.isCollapsed === false;

    if (!onLink && !selecting) {
        start();
    }
};

const cancel = (): void => {
    if (saving.value) {
        return;
    }

    draft.value = props.value ?? '';
    savedValue.value = props.value;
    error.value = null;
    conflict.value = false;
    editing.value = false;
};

const save = (): void => {
    if (saving.value || conflict.value) {
        return;
    }

    if (!dirty.value) {
        editing.value = false;

        return;
    }

    const sent = nextValue.value;
    const previous = props.value;
    saving.value = true;
    error.value = null;
    conflict.value = false;
    saved.value = false;
    let completed = false;

    router.put(
        TaskController.update.url(props.taskId),
        { description: sent, description_original: savedValue.value },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                completed = true;
                savedValue.value =
                    props.value === previous ? sent : props.value;
                saved.value = true;

                if (nextValue.value === sent) {
                    const confirmed = savedValue.value;
                    draft.value = confirmed ?? '';
                    savedValue.value = confirmed;
                    editing.value = false;
                }
            },
            onError: (errors) => {
                completed = true;
                conflict.value = errors.description_conflict === '1';
                error.value =
                    errors.description ??
                    'Could not save. Your text is still here.';
            },
            onFinish: () => {
                saving.value = false;

                if (!completed) {
                    error.value = 'Could not save. Your text is still here.';
                } else if (
                    error.value === null &&
                    nextValue.value !== savedValue.value
                ) {
                    save();
                }
            },
        },
    );
};

const onFocusOut = (): void => {
    if (focusTimer !== null) {
        clearTimeout(focusTimer);
    }

    focusTimer = window.setTimeout(() => {
        focusTimer = null;

        if (
            !editing.value ||
            wrapper.value?.contains(document.activeElement) === true ||
            error.value !== null
        ) {
            return;
        }

        save();
    }, 0);
};

const reloadAfterConflict = (): void => {
    if (
        window.confirm('Discard your draft and reload the latest description?')
    ) {
        cancel();
        router.reload();
    }
};

const guard = (event: BeforeUnloadEvent): void => {
    if (pending.value) {
        event.preventDefault();
    }
};

window.addEventListener('beforeunload', guard);

const stopListening = router.on('before', ({ detail: { visit } }) => {
    if (
        pending.value &&
        !visit.prefetch &&
        visit.method === 'get' &&
        visit.url.href !== window.location.href
    ) {
        return window.confirm('Discard the unsaved description?');
    }
});

onBeforeUnmount(() => {
    if (focusTimer !== null) {
        clearTimeout(focusTimer);
    }

    window.removeEventListener('beforeunload', guard);
    stopListening();
});
</script>

<template>
    <div ref="wrapper" class="flex flex-col gap-2" @focusout="onFocusOut">
        <div
            v-if="editing"
            class="overflow-hidden rounded-lg border border-input bg-background transition-colors focus-within:border-ring"
            @keydown.ctrl.enter.prevent="save"
            @keydown.meta.enter.prevent="save"
        >
            <Suspense>
                <RichTextEditor v-model="draft" :placeholder="placeholder" />

                <template #fallback>
                    <div class="flex flex-col gap-2 p-3" aria-hidden="true">
                        <Skeleton class="h-4 w-2/3 animate-pulse" />
                        <Skeleton class="h-4 w-1/2 animate-pulse" />
                    </div>
                </template>
            </Suspense>

            <div
                class="flex flex-wrap items-center gap-2 border-t border-border px-3 py-2"
            >
                <span
                    class="mr-auto text-xs text-muted-foreground"
                    :class="error && 'text-destructive'"
                    aria-live="polite"
                    :role="error ? 'alert' : 'status'"
                >
                    <template v-if="saving">
                        <Loader2
                            class="mr-1 inline size-3.5 animate-spin"
                            aria-hidden="true"
                        />
                        Saving…
                    </template>
                    <template v-else-if="error">{{ error }}</template>
                    <template v-else-if="dirty">Unsaved changes</template>
                    <template v-else>Changes saved</template>
                </span>
                <button
                    type="button"
                    class="min-h-11 rounded-md px-2 py-1.5 text-xs text-muted-foreground hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none md:min-h-8"
                    :disabled="saving"
                    @click="cancel"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="min-h-11 rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none disabled:opacity-60 md:min-h-8"
                    :disabled="saving || !dirty || conflict"
                    @click="save"
                >
                    {{ error ? 'Retry save' : 'Save' }}
                </button>
                <button
                    v-if="conflict"
                    type="button"
                    class="min-h-11 rounded-md px-2 text-xs underline focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none md:min-h-8"
                    @click="reloadAfterConflict"
                >
                    Reload latest
                </button>
            </div>
        </div>

        <div v-else-if="editable && value" class="group/description relative">
            <div
                class="rich-text cursor-text rounded-lg border border-transparent py-2 pr-12 pl-2 transition-colors hover:border-input"
                @click="startUnlessReading"
                v-html="rendered"
            />

            <button
                type="button"
                class="absolute top-0 right-0 inline-flex size-11 items-center justify-center rounded-md text-muted-foreground opacity-0 transition-opacity group-hover/description:opacity-100 hover:bg-accent hover:text-foreground focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none md:size-8 pointer-coarse:opacity-100"
                aria-label="Edit the description"
                @click="start"
            >
                <Pencil class="size-4" aria-hidden="true" />
            </button>
        </div>

        <button
            v-else-if="editable"
            type="button"
            class="min-h-11 w-full cursor-text rounded-lg border border-dashed border-input px-3 py-2 text-left text-sm text-muted-foreground transition-colors hover:bg-accent/50 focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
            aria-label="Add a description"
            @click="start"
        >
            {{ placeholder }}
        </button>

        <div v-else class="px-2 py-1.5">
            <div v-if="value" class="rich-text" v-html="rendered" />
            <span v-else class="text-sm text-muted-foreground"
                >No description.</span
            >
        </div>

        <p
            v-if="saved && !editing"
            class="flex items-center gap-1 text-xs text-muted-foreground"
            role="status"
        >
            <Check class="size-3.5" aria-hidden="true" />
            Saved
        </p>
    </div>
</template>
