<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { nextTick, ref } from 'vue';
import ProjectTaskController from '@/actions/App/Http/Controllers/Project/ProjectTaskController';
import { t } from '@/lib/i18n';

const props = withDefaults(
    defineProps<{
        projectId: string;
        sectionId: string | null;
        dueAt?: string | null;
        compact?: boolean;
        /** Board column: the trigger spans the column and the input looks like the card it becomes. */
        card?: boolean;
        hideTrigger?: boolean;
    }>(),
    { dueAt: null, compact: false, card: false, hideTrigger: false },
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

defineExpose({ start });

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
    <div :class="compact ? '' : card ? 'px-2 pb-2' : 'px-4 py-2 md:pl-21'">
        <button
            v-if="!open && !hideTrigger"
            type="button"
            data-add-task
            class="inline-flex items-center text-sm text-muted-foreground hover:text-foreground"
            :class="
                compact
                    ? 'min-h-6 w-full rounded px-1.5 text-xs hover:bg-accent'
                    : card
                      ? 'min-h-11 w-full gap-1.5 rounded-md px-2 hover:bg-accent md:min-h-8'
                      : 'min-h-11 md:min-h-6'
            "
            @click="start"
        >
            <template v-if="card">
                <Plus class="size-4" aria-hidden="true" />
                {{ t('Add task') }}
            </template>
            <template v-else>{{
                compact ? t('+ Add') : t('+ Add task')
            }}</template>
        </button>

        <input
            v-if="open"
            ref="input"
            v-model="title"
            type="text"
            :placeholder="t('Task name')"
            :disabled="saving"
            class="w-full disabled:opacity-50"
            :class="
                card
                    ? 'min-h-11 rounded-md border border-border bg-background px-3 py-2.5 text-sm shadow-xs placeholder:text-muted-foreground focus:border-foreground/25 focus:outline-none'
                    : [
                          'rounded border border-input bg-transparent',
                          compact
                              ? 'px-1.5 py-0.5 text-xs'
                              : 'px-2 py-1 text-sm',
                      ]
            "
            @keydown.enter.prevent="submit"
            @keydown.esc.prevent="close"
            @blur="title.trim() === '' ? close() : undefined"
        />
    </div>
</template>
