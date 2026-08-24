<script setup lang="ts">
import { listColumns } from '@/modules/task/listColumns';

/**
 * The list's column names, once, above every section.
 *
 * Drawn only from `md` up: below it a row reflows onto two lines and the cells stop being
 * columns, so a header would be labelling a layout that is no longer there.
 *
 * The widths come from `listColumns`, which the rows read too — a header that has drifted from
 * the cell beneath it is worse than no header, because it labels the wrong thing with confidence.
 */
defineProps<{
    fields?: { id: string; name: string; type: string }[];
}>();
</script>

<template>
    <!-- The transparent side borders are not decoration: each section below is a bordered box,
         so its rows start one pixel further in. Without them the header is off by one. -->
    <div
        class="hidden items-center gap-3 border border-transparent border-b-border px-4 pb-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase md:flex"
        aria-hidden="true"
    >
        <span class="flex-1">Task name</span>

        <span v-for="field in fields" :key="field.id" :class="listColumns.field" :title="field.name">
            {{ field.name }}
        </span>

        <span :class="listColumns.assignee">Assignee</span>
        <span :class="listColumns.due">Due</span>
        <span :class="listColumns.priority">Priority</span>
    </div>
</template>
