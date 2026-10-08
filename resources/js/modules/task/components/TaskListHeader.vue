<script setup lang="ts">
import type { ListColumn } from '@/modules/task/listColumns';
import {
    defaultListColumns,
    listColumns,
    widthFor,
} from '@/modules/task/listColumns';

withDefaults(
    defineProps<{
        columns?: ListColumn[];
        numbered?: boolean;
    }>(),
    { numbered: true, columns: () => defaultListColumns },
);
</script>

<template>
    <div
        class="hidden items-stretch border-y border-border text-[11px] font-semibold tracking-wide text-muted-foreground uppercase md:flex"
        aria-hidden="true"
    >
        <span
            v-if="numbered"
            class="flex items-center"
            :class="[listColumns.index, listColumns.cell]"
            >#</span
        >
        <span
            class="flex items-center"
            :class="[listColumns.name, listColumns.cell]"
            >Task name</span
        >

        <span
            v-for="column in columns"
            :key="column.key"
            class="flex items-center"
            :class="[widthFor(column.kind), listColumns.cell]"
            :title="column.label"
        >
            <span class="truncate">{{ column.label }}</span>
        </span>
        <span :class="listColumns.filler" />
    </div>
</template>
