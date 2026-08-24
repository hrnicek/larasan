<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Maximize2, PanelRightClose } from '@lucide/vue';
import { nextTick, onMounted, onUnmounted, ref } from 'vue';
import TaskDetailBody from '@/modules/task/components/TaskDetailBody.vue';
import type { TaskAssignee, TaskDetail, TaskFeed } from '@/modules/task/types';
import { show } from '@/routes/tasks';

/**
 * The task detail as an overlay panel: over the content, under the topbar, with the rest of the
 * application dimmed behind it.
 *
 * The task itself is `TaskDetailBody`, which the task's own page renders too — one component in
 * both places, so neither can drift. What lives here is only what an overlay owes: a way out, a
 * way to make it a page, a focus trap and the place you came from.
 */
defineProps<{
    detail: TaskDetail;
    /** Deferred: absent until the follow-up request lands (TASK-100-011). */
    activity?: TaskFeed;
    members: TaskAssignee[];
    priorities: string[];
}>();

const emit = defineEmits<{ close: [] }>();

const panel = ref<HTMLElement | null>(null);

/*
 * Remembered as an id rather than an element. The row or card that opened the panel is
 * re-rendered while the panel is open, so the element reference goes stale and focus would
 * land on the document — which is the opposite of not losing your place.
 */
let restoreFocusToTask: string | null = null;

/**
 * `Tab` cycles inside the panel while it is open, and `Esc` closes it. A panel you can tab out
 * of is a panel that loses your place in the list behind it.
 */
const onKeydown = (event: KeyboardEvent): void => {
    if (event.key === 'Escape') {
        event.preventDefault();
        emit('close');

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
    const origin = (
        document.activeElement as HTMLElement | null
    )?.closest<HTMLElement>('[data-task-id]');

    restoreFocusToTask = origin?.dataset.taskId ?? null;
    panel.value?.focus();

    // The page behind the panel must not scroll under it; the panel scrolls on its own.
    document.body.style.overflow = 'hidden';
});

onUnmounted(() => {
    document.body.style.overflow = '';

    if (restoreFocusToTask === null) {
        return;
    }

    // Back to the row or card the panel was opened from: the keyboard equivalent of not
    // losing your place.
    void nextTick(() => {
        document
            .querySelector<HTMLElement>(
                `[data-task-id="${restoreFocusToTask}"]`,
            )
            ?.focus();
    });
});
</script>

<template>
    <Teleport to="body">
        <!--
            Anchored below the topbar rather than over it: the topbar is where you get out of the
            task and into anything else, and a panel that covers it is a panel with one exit.
        -->
        <div class="fixed inset-x-0 top-13 bottom-0 z-40">
            <div
                class="absolute inset-0 bg-black/25 backdrop-blur-[1px]"
                @click="emit('close')"
            />

            <section
                ref="panel"
                tabindex="-1"
                role="dialog"
                aria-modal="true"
                :aria-label="detail.task.title"
                class="absolute inset-x-0 bottom-0 flex h-[88%] flex-col overflow-hidden rounded-t-xl border border-border bg-background shadow-2xl outline-none md:inset-y-0 md:right-0 md:left-auto md:h-auto md:w-[86%] md:rounded-t-none md:rounded-l-xl lg:w-[64%] xl:w-[55%]"
                @keydown="onKeydown"
            >
                <header
                    class="flex h-12 shrink-0 items-center justify-end gap-1 border-b border-border px-2"
                >
                    <Link
                        :href="show(detail.task.id)"
                        class="inline-flex size-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                        aria-label="Open as a full page"
                    >
                        <Maximize2 class="size-4" />
                    </Link>

                    <button
                        type="button"
                        class="inline-flex size-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                        aria-label="Close"
                        @click="emit('close')"
                    >
                        <PanelRightClose class="size-4" />
                    </button>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                    <TaskDetailBody
                        :detail="detail"
                        :activity="activity"
                        :members="members"
                        :priorities="priorities"
                    />
                </div>
            </section>
        </div>
    </Teleport>
</template>
