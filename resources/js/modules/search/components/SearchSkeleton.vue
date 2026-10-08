<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PageHeader from '@/components/PageHeader.vue';
import { Skeleton } from '@/components/ui/skeleton';

const filterWidths = ['w-32', 'w-28', 'w-36'];
const rowWidths = ['w-2/3', 'w-1/2', 'w-3/5', 'w-2/5', 'w-1/2'];
</script>

<template>
    <div class="flex h-full flex-1 flex-col">
        <Head title="Search" />

        <PageHeader
            title="Search"
            description="Task names and descriptions, across every project you can reach"
        />

        <div
            class="flex flex-1 flex-col gap-4 px-4 py-4 md:px-6"
            aria-hidden="true"
        >
            <Skeleton class="h-10 w-full" />

            <div class="flex flex-wrap gap-2">
                <Skeleton
                    v-for="(width, index) in filterWidths"
                    :key="index"
                    class="h-8"
                    :class="width"
                />
            </div>

            <div class="divide-y divide-border border-y border-border">
                <div
                    v-for="(width, index) in rowWidths"
                    :key="index"
                    class="flex items-center gap-3 py-2.5"
                >
                    <Skeleton class="size-4 rounded" />
                    <Skeleton class="h-4" :class="width" />
                </div>
            </div>
        </div>
    </div>
</template>
