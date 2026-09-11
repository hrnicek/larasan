<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PageHeader from '@/components/PageHeader.vue';
import { Skeleton } from '@/components/ui/skeleton';

const nameWidths = ['w-48', 'w-36', 'w-56', 'w-40', 'w-32', 'w-44'];
</script>

<template>
    <div class="flex flex-col">
        <Head title="Projects" />

        <PageHeader
            title="Projects"
            description="Everything you can reach in this workspace"
        />

        <div
            class="flex flex-col divide-y divide-border border-b border-border"
            aria-hidden="true"
        >
            <div
                v-for="(width, index) in nameWidths"
                :key="index"
                class="flex min-h-11 items-center gap-3 px-4 py-2 md:px-6"
            >
                <Skeleton class="size-7 rounded-md" />
                <Skeleton class="h-4" :class="width" />
            </div>
        </div>
    </div>
</template>
