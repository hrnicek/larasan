<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { nextTick, onMounted, onUnmounted, ref } from 'vue';
import type { TaskDetail } from '@/modules/task/types';

/**
 * One task, rendered the same way whether it is a panel over a list or a page of its own.
 * Two components would drift, and the second would be the one nobody tests.
 */
const props = defineProps<{
    detail: TaskDetail;
    /** A panel can be closed; a page has nowhere to close to. */
    dismissible: boolean;
}>();

const emit = defineEmits<{ close: [] }>();

const panel = ref<HTMLElement | null>(null);

/*
 * Remembered as an id rather than an element. The row or card that opened the panel is
 * re-rendered while the panel is open, so the element reference goes stale and focus would
 * land on the document — which is the opposite of not losing your place.
 */
let restoreFocusToTask: string | null = null;

const close = (): void => {
    if (props.dismissible) {
        emit('close');
    }
};

/**
 * `Tab` cycles inside the panel while it is open, and `Esc` closes it. A panel you can tab
 * out of is a panel that loses your place in the list behind it.
 */
const onKeydown = (event: KeyboardEvent): void => {
    if (event.key === 'Escape') {
        event.preventDefault();
        close();

        return;
    }

    if (event.key !== 'Tab' || panel.value === null) {
        return;
    }

    const focusable = Array.from(
        panel.value.querySelectorAll<HTMLElement>(
            'a[href], button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])',
        ),
    );

    if (focusable.length === 0) {
        return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const active = document.activeElement as HTMLElement | null;

    if (event.shiftKey && active === first) {
        event.preventDefault();
        last.focus();

        return;
    }

    if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
    }
};

onMounted(() => {
    if (!props.dismissible) {
        return;
    }

    const origin = (document.activeElement as HTMLElement | null)?.closest<HTMLElement>('[data-task-id]');

    restoreFocusToTask = origin?.dataset.taskId ?? null;
    panel.value?.focus();
});

onUnmounted(() => {
    if (restoreFocusToTask === null) {
        return;
    }

    // Back to the row or card the panel was opened from: the keyboard equivalent of not
    // losing your place.
    void nextTick(() => {
        document.querySelector<HTMLElement>(`[data-task-id="${restoreFocusToTask}"]`)?.focus();
    });
});

defineExpose({ close });
</script>

<template>
    <section
        ref="panel"
        tabindex="-1"
        class="flex flex-col gap-4 rounded-lg border bg-card p-4 outline-none"
        :role="dismissible ? 'dialog' : undefined"
        :aria-modal="dismissible ? 'true' : undefined"
        :aria-label="detail.task.title"
        @keydown="onKeydown"
    >
        <header class="flex items-start justify-between gap-3">
            <div class="flex flex-col gap-1">
                <Link
                    v-if="detail.task.parent"
                    :href="`/tasks/${detail.task.parent.id}`"
                    class="text-xs text-muted-foreground hover:text-foreground"
                >
                    ↑ {{ detail.task.parent.title }}
                </Link>
                <h2 class="text-lg font-semibold" :class="detail.task.completedAt ? 'line-through text-muted-foreground' : ''">
                    {{ detail.task.title }}
                </h2>
            </div>

            <button
                v-if="dismissible"
                type="button"
                class="rounded px-2 text-sm text-muted-foreground hover:text-foreground"
                aria-label="Close"
                @click="close"
            >
                ✕
            </button>
        </header>

        <dl class="grid grid-cols-2 gap-2 text-sm">
            <div>
                <dt class="text-xs text-muted-foreground">Assignee</dt>
                <dd>{{ detail.task.assignee?.name ?? 'Unassigned' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Due</dt>
                <dd>{{ detail.task.dueAt ? detail.task.dueAt.slice(0, 10) : '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Priority</dt>
                <dd class="capitalize">{{ detail.task.priority }}</dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Created by</dt>
                <dd>{{ detail.task.creator?.name ?? '—' }}</dd>
            </div>
        </dl>

        <section>
            <h3 class="mb-1 text-xs text-muted-foreground">Description</h3>
            <p class="whitespace-pre-line text-sm">
                {{ detail.task.description || 'No description yet.' }}
            </p>
        </section>

        <section>
            <h3 class="mb-1 text-xs text-muted-foreground">Projects</h3>
            <ul v-if="detail.placements.length" class="flex flex-col gap-1 text-sm">
                <li v-for="placement in detail.placements" :key="placement.placementId">
                    {{ placement.project.name }}
                    <span class="text-muted-foreground">· {{ placement.section?.name ?? 'No section' }}</span>
                </li>
            </ul>
            <!-- ADR-0003: a task in no project is still a task, and the screen says so. -->
            <p v-else class="text-sm text-muted-foreground">
                In no project — reachable from My Tasks and search.
            </p>
        </section>

        <section>
            <h3 class="mb-1 text-xs text-muted-foreground">Subtasks</h3>
            <ul v-if="detail.subtasks.length" class="flex flex-col gap-1 text-sm">
                <li v-for="subtask in detail.subtasks" :key="subtask.id">
                    <Link
                        :href="`/tasks/${subtask.id}`"
                        :class="subtask.completedAt ? 'line-through text-muted-foreground' : ''"
                    >
                        {{ subtask.title }}
                    </Link>
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">No subtasks.</p>
        </section>
    </section>
</template>
