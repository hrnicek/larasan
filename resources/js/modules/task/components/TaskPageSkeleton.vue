<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';
import TaskActivitySkeleton from '@/modules/task/components/TaskActivitySkeleton.vue';

const fieldWidths = ['w-32', 'w-24', 'w-40', 'w-28'];
</script>

<template>
    <div class="mx-auto flex w-full max-w-3xl flex-col" aria-hidden="true">
        <div class="px-4 pt-6 pb-3 md:px-6">
            <Skeleton class="h-4 w-28" />
        </div>

        <div
            class="flex h-12 items-center gap-2 border-b border-border px-4 md:px-6"
        >
            <Skeleton class="h-8 w-28" />
            <Skeleton class="ml-auto size-8" />
            <Skeleton class="size-8" />
        </div>

        <div class="space-y-5 px-4 py-5 md:px-6">
            <Skeleton class="h-7 w-3/4" />

            <div class="space-y-3">
                <div
                    v-for="(width, index) in fieldWidths"
                    :key="index"
                    class="flex h-8 items-center gap-4"
                >
                    <Skeleton class="h-4 w-20" />
                    <Skeleton class="h-4" :class="width" />
                </div>
            </div>

            <Skeleton class="h-24 w-full" />
        </div>

        <TaskActivitySkeleton />
    </div>
</template>
