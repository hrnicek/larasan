<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CalendarPlus, TriangleAlert } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import TaskController from '@/actions/App/Http/Controllers/Task/TaskController';

/**
 * The due date, changed from the row or the panel. Only this field is sent: `tasks.update`
 * treats an absent field as untouched, so a date change cannot clear a description the row
 * never carried.
 */
const props = defineProps<{
    taskId: string;
    dueAt: string | null;
    editable: boolean;
}>();

const saving = ref(false);
const editing = ref(false);
const input = ref<HTMLInputElement | null>(null);

const date = computed<string>(() => (props.dueAt === null ? '' : props.dueAt.slice(0, 10)));

/**
 * Overdue is said twice — in red **and** with an icon — because colour alone is not a message
 * somebody who cannot see it receives. Compared as dates rather than instants: a task due today
 * is not late at nine in the morning.
 */
const overdue = computed<boolean>(() => {
    if (date.value === '' || props.dueAt === null) {
        return false;
    }

    const today = new Date();
    const midnight = new Date(today.getFullYear(), today.getMonth(), today.getDate());

    return new Date(`${date.value}T00:00:00`) < midnight;
});

/** The reader's own locale, short: `12 Aug` is a date, `2026-08-12` is a value. */
const label = computed<string>(() =>
    date.value === ''
        ? ''
        : new Date(`${date.value}T00:00:00`).toLocaleDateString(undefined, { day: 'numeric', month: 'short' }),
);

async function edit(): Promise<void> {
    editing.value = true;
    await nextTick();
    input.value?.focus();
    input.value?.showPicker?.();
}

function change(event: Event): void {
    const value = (event.target as HTMLInputElement).value;

    saving.value = true;

    router.put(
        TaskController.update.url(props.taskId),
        // Empty means cleared, which is a null the server is allowed to act on.
        { due_at: value === '' ? null : value },
        {
            preserveScroll: true,
            onFinish: () => {
                saving.value = false;
                editing.value = false;
            },
        },
    );
}
</script>

<template>
    <span v-if="!editable" class="inline-flex items-center gap-1 text-xs text-muted-foreground">
        <TriangleAlert v-if="overdue" class="size-3.5 text-destructive" aria-hidden="true" />
        <span :class="overdue ? 'text-destructive' : ''">{{ label || '—' }}</span>
        <span v-if="overdue" class="sr-only">overdue</span>
    </span>

    <input
        v-else-if="editing"
        ref="input"
        type="date"
        :value="date"
        :disabled="saving"
        aria-label="Due date"
        class="rounded-md border border-input bg-transparent px-1.5 py-0.5 text-xs disabled:opacity-50"
        @change="change"
        @blur="editing = false"
    />

    <!--
        An empty cell is a target rather than a gap: the dashed outline says "something goes here"
        the way the reference's placeholder does, instead of leaving a browser's `dd.mm.yyyy` in
        every row of a list where most tasks have no date.
    -->
    <button
        v-else
        type="button"
        class="inline-flex items-center gap-1 rounded-md px-1 py-0.5 text-xs transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
        :class="overdue ? 'text-destructive' : 'text-muted-foreground'"
        :aria-label="dueAt === null ? 'Set a due date' : `Due ${label}${overdue ? ', overdue' : ''}. Change it`"
        @click="edit"
    >
        <TriangleAlert v-if="overdue" class="size-3.5" aria-hidden="true" />
        <template v-if="dueAt === null">
            <span class="flex size-4 items-center justify-center rounded border border-dashed border-muted-foreground/50">
                <CalendarPlus class="size-2.5" />
            </span>
        </template>
        <span v-else>{{ label }}</span>
    </button>
</template>
