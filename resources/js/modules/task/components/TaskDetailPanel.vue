<script lang="ts">
// Module scope: opening a subtask re-keys the panel, and the replacement inherits the row to return focus to.
let focusAwaitingRestore: string | null = null;
</script>

<script setup lang="ts">
import { nextTick, onMounted, onUnmounted, ref } from 'vue';
import TaskActivitySkeleton from '@/modules/task/components/TaskActivitySkeleton.vue';
import TaskDetailBody from '@/modules/task/components/TaskDetailBody.vue';
import TaskDetailToolbar from '@/modules/task/components/TaskDetailToolbar.vue';
import type { TaskAssignee, TaskDetail, TaskFeed } from '@/modules/task/types';

defineProps<{
    detail: TaskDetail;
    /** Deferred prop, undefined until loaded. */
    activity?: TaskFeed;
    members: TaskAssignee[];
    priorities: string[];
}>();

const emit = defineEmits<{ close: []; open: [taskId: string] }>();

const panel = ref<HTMLElement | null>(null);

// An id, not an element: the originating row re-renders while the panel is open.
let restoreFocusToTask: string | null = null;

const scrolled = ref(false);

const onScroll = (event: Event): void => {
    scrolled.value = (event.target as HTMLElement).scrollTop > 56;
};

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
    const origin = (document.activeElement as HTMLElement | null)?.closest<HTMLElement>('[data-task-id]');

    restoreFocusToTask = focusAwaitingRestore ?? origin?.dataset.taskId ?? null;
    focusAwaitingRestore = null;
    panel.value?.focus();

    document.body.style.overflow = 'hidden';
});

onUnmounted(() => {
    document.body.style.overflow = '';

    if (restoreFocusToTask === null) {
        return;
    }

    focusAwaitingRestore = restoreFocusToTask;

    // A panel replaced in the same render has mounted and claimed the target by now.
    void nextTick(() => {
        if (focusAwaitingRestore === null) {
            return;
        }

        document.querySelector<HTMLElement>(`[data-task-id="${focusAwaitingRestore}"]`)?.focus();
        focusAwaitingRestore = null;
    });
});
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-x-0 top-13 bottom-0 z-40">
            <div class="absolute inset-0 bg-black/25 backdrop-blur-[1px]" @click="emit('close')" />

            <section
                ref="panel"
                tabindex="-1"
                role="dialog"
                aria-modal="true"
                :aria-label="detail.task.title"
                :class="[
                    'absolute inset-x-0 bottom-0 flex h-[88%] flex-col overflow-hidden border border-border bg-background shadow-2xl outline-none animate-in duration-300 md:slide-in-from-right max-sm:slide-in-from-bottom',
                    'md:inset-y-0 md:right-0 md:left-auto md:h-auto md:w-[86%] md:rounded-none',
                    'lg:w-[64%] xl:w-[55%]'
                ]"
                @keydown="onKeydown"
            >
                <TaskDetailToolbar
                    :detail="detail"
                    variant="panel"
                    :collapsed="scrolled"
                    @close="emit('close')"
                    @deleted="emit('close')"
                />

                <div class="min-h-0 flex-1 overflow-y-auto [scrollbar-width:thin]" @scroll="onScroll">
                    <TaskDetailBody
                        :detail="detail"
                        :activity="activity"
                        :members="members"
                        :priorities="priorities"
                        @open="(taskId) => emit('open', taskId)"
                    />

                    <TaskActivitySkeleton v-if="activity === undefined" />
                </div>
            </section>
        </div>
    </Teleport>
</template>
