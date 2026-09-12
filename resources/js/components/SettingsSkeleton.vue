<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';

const labelWidths = ['w-20', 'w-28', 'w-24'];
</script>

<template>
    <div class="space-y-6" aria-hidden="true">
        <div class="space-y-1.5">
            <Skeleton class="h-5 w-40" />
            <Skeleton class="h-4 w-72 max-w-full" />
        </div>

        <div
            v-for="(width, index) in labelWidths"
            :key="index"
            class="grid gap-2"
        >
            <Skeleton class="h-4" :class="width" />
            <Skeleton class="h-9 w-full" />
        </div>

        <Skeleton class="h-9 w-24" />
    </div>
</template>
