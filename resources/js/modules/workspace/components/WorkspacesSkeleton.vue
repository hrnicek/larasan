<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Skeleton } from '@/components/ui/skeleton';

const nameWidths = ['w-40', 'w-32', 'w-48'];
</script>

<template>
    <div class="flex max-w-3xl flex-col space-y-6 p-4 md:p-6">
        <Head title="Workspaces" />

        <Heading
            title="Workspaces"
            description="The workspaces you belong to"
        />

        <div class="divide-y rounded-lg border" aria-hidden="true">
            <div
                v-for="(width, index) in nameWidths"
                :key="index"
                class="flex items-center justify-between gap-4 p-4"
            >
                <div class="space-y-2">
                    <Skeleton class="h-4" :class="width" />
                    <Skeleton class="h-3 w-24" />
                </div>

                <Skeleton class="h-9 w-20" />
            </div>
        </div>
    </div>
</template>
