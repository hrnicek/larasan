<script setup lang="ts">
import { listColumns } from '@/modules/task/listColumns';

/**
 * The list's column names, once, above every section.
 *
 * Drawn once per section rather than once per screen: a section is a table, and a table's column
 * names belong to it. Scrolling past three sections with the header left behind at the top is
 * what makes a wide list unreadable.
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
    <!-- Inside the section, directly above its rows, with the same horizontal padding they have
         so the labels sit over the cells rather than near them. -->
    <div
        class="hidden items-center gap-3 border-b border-border px-4 py-1.5 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase md:flex"
        aria-hidden="true"
    >
        <span class="w-5 shrink-0 text-right">#</span>
        <span class="flex-1">Task name</span>

        <span v-for="field in fields" :key="field.id" :class="listColumns.field" :title="field.name">
            {{ field.name }}
        </span>

        <span :class="listColumns.assignee">Assignee</span>
        <span :class="listColumns.due">Due</span>
        <span :class="listColumns.priority">Priority</span>
    </div>
</template>
