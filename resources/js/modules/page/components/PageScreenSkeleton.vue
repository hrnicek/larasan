<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';

/**
 * A page of a project on its way: the tree beside it and the document itself, laid out as
 * `pages/Show` lays them out so the editor arrives where its placeholder was.
 */
const treeRows: { depth: number; width: string }[] = [
    { depth: 0, width: 'w-40' },
    { depth: 1, width: 'w-32' },
    { depth: 1, width: 'w-36' },
    { depth: 0, width: 'w-44' },
    { depth: 0, width: 'w-28' },
];

const lineWidths = [
    'w-full',
    'w-11/12',
    'w-full',
    'w-3/4',
    'w-full',
    'w-5/6',
    'w-2/3',
];
</script>

<template>
    <div class="flex w-full" aria-hidden="true">
        <aside
            class="hidden w-72 shrink-0 border-r border-border py-4 lg:block"
        >
            <div class="px-3 pb-3">
                <Skeleton class="h-4 w-32" />
            </div>

            <div class="flex flex-col gap-1 px-3">
                <div
                    v-for="(row, index) in treeRows"
                    :key="index"
                    class="flex h-8 items-center gap-2"
                    :class="row.depth > 0 ? 'pl-4' : ''"
                >
                    <Skeleton class="size-4 rounded" />
                    <Skeleton class="h-4" :class="row.width" />
                </div>
            </div>
        </aside>

        <div class="mx-auto flex w-full max-w-3xl flex-col px-4 py-6 md:px-8">
            <div class="flex h-7 items-center justify-end pb-2">
                <Skeleton class="h-4 w-16" />
            </div>

            <Skeleton class="mt-2 h-9 w-2/3" />

            <div class="mt-6 space-y-3">
                <Skeleton
                    v-for="(width, index) in lineWidths"
                    :key="index"
                    class="h-4"
                    :class="width"
                />
            </div>
        </div>
    </div>
</template>
