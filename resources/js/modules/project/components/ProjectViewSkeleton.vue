<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';
import TaskListSkeleton from '@/modules/task/components/TaskListSkeleton.vue';

/**
 * A project's view while its payload is on the way: the sections of a list, the columns of a
 * board, the month of a calendar, the table of files or the tree of pages.
 *
 * Drawn to each view's own proportions, so the screen that arrives replaces it in place. A
 * skeleton of the wrong shape is a layout jump with an extra step.
 */
defineProps<{ view: string }>();

const sectionRows = [5, 3];
const boardCards = [3, 2, 4, 1];
const pageDepths = [0, 1, 1, 0, 1, 0];
</script>

<template>
    <div aria-hidden="true">
        <div
            v-if="view === 'board'"
            class="flex gap-4 overflow-hidden px-4 pt-4 pb-3 md:px-6"
        >
            <div
                v-for="(cards, index) in boardCards"
                :key="index"
                class="w-full shrink-0 flex-col rounded-lg border border-border bg-muted/30 md:w-72"
                :class="index === 0 ? 'flex' : 'hidden md:flex'"
            >
                <div class="flex items-center gap-2 px-3 py-2.5">
                    <Skeleton class="size-2 rounded-full" />
                    <Skeleton class="h-4 max-w-32 flex-1" />
                    <Skeleton class="h-4 w-6 rounded-full" />
                </div>

                <div class="flex flex-col gap-2 p-2">
                    <div
                        v-for="card in cards"
                        :key="card"
                        class="space-y-2 rounded-md border border-border bg-card p-3"
                    >
                        <Skeleton class="h-4 w-4/5" />
                        <Skeleton class="h-3 w-1/3" />
                    </div>
                </div>
            </div>
        </div>

        <div v-else-if="view === 'calendar'" class="mt-4">
            <div
                class="hidden grid-cols-7 border-t border-l border-border md:grid"
            >
                <div
                    v-for="weekday in 7"
                    :key="`weekday-${weekday}`"
                    class="border-r border-b border-border bg-muted/40 px-1.5 py-2"
                >
                    <Skeleton class="h-3 w-8" />
                </div>

                <div
                    v-for="day in 35"
                    :key="`day-${day}`"
                    class="h-[146px] border-r border-b border-border p-1.5"
                >
                    <Skeleton class="h-3 w-4" />
                </div>
            </div>

            <TaskListSkeleton class="md:hidden" :rows="6" />
        </div>

        <div v-else-if="view === 'files'" class="px-4 pt-2 md:px-6">
            <div
                v-for="row in 6"
                :key="row"
                class="flex items-center gap-3 border-b border-border py-2.5"
            >
                <Skeleton class="size-8 rounded-md" />
                <Skeleton class="h-4 max-w-64 flex-1" />
                <Skeleton class="hidden h-3 w-24 md:block" />
                <Skeleton class="hidden h-3 w-16 md:block" />
            </div>
        </div>

        <div
            v-else-if="view === 'pages'"
            class="flex flex-col gap-1 px-4 pt-4 md:px-6"
        >
            <div
                v-for="(depth, index) in pageDepths"
                :key="index"
                class="flex h-8 items-center gap-2"
                :class="depth > 0 ? 'pl-6' : ''"
            >
                <Skeleton class="size-4 rounded" />
                <Skeleton class="h-4" :class="depth > 0 ? 'w-40' : 'w-56'" />
            </div>
        </div>

        <div v-else class="flex flex-col">
            <section
                v-for="(rows, index) in sectionRows"
                :key="index"
                class="border-b border-border"
            >
                <div class="flex h-11 items-center gap-2 px-4">
                    <Skeleton class="size-4 rounded" />
                    <Skeleton class="h-4 w-32" />
                </div>

                <TaskListSkeleton :rows="rows" />
            </section>
        </div>
    </div>
</template>
