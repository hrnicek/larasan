<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';

const lineWidths = ['w-48', 'w-56', 'w-40'];
</script>

<template>
    <div class="space-y-4 px-6 py-4" aria-hidden="true">
        <Skeleton class="h-10 w-32" />

        <div class="space-y-3">
            <div
                v-for="(width, index) in lineWidths"
                :key="index"
                class="flex gap-3"
            >
                <Skeleton class="size-8 shrink-0 rounded-full" />
                <div class="min-w-0 flex-1 space-y-2">
                    <Skeleton class="h-4 w-24" />
                    <Skeleton class="h-3" :class="width" />
                </div>
            </div>
        </div>
    </div>
</template>
