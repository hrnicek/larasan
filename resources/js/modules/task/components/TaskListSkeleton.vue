<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';

/**
 * What a column looks like while its rows are on their way. The header is already drawn by
 * the group around it, so this stands in for the rows only.
 */
withDefaults(defineProps<{ rows?: number }>(), { rows: 5 });
</script>

<template>
    <div class="divide-y border-t">
        <div v-for="row in rows" :key="row" class="flex items-center gap-3 px-4 py-2">
            <Skeleton class="size-4 rounded" />
            <Skeleton class="h-4 flex-1" />
            <Skeleton class="h-4 w-20" />
        </div>
    </div>
</template>
