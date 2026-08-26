<script setup lang="ts">
import type { ListColumn } from '@/modules/task/listColumns';
import { defaultListColumns, listColumns, widthFor } from '@/modules/task/listColumns';

/**
 * The list's column names, once above the whole list.
 *
 * Once rather than once per section: a section is a group of rows in one table, not a table of
 * its own, and repeating `Task name / Assignee / Due` above every group is the same six words
 * drawn five times on a screen somebody is scanning. It is sticky with the page header instead,
 * so it is still there at the bottom of the list.
 *
 * Drawn only from `md` up: below it a row reflows onto two lines and the cells stop being
 * columns, so a header would be labelling a layout that is no longer there.
 *
 * The widths come from `listColumns`, which the rows read too — a header that has drifted from
 * the cell beneath it is worse than no header, because it labels the wrong thing with confidence.
 * The **order** comes from the server for the same reason: the rows read the same list, so the two
 * cannot disagree about which column is which (TASK-240-010).
 */
withDefaults(defineProps<{
    /**
     * The columns after the name, in the order the project draws them (TASK-240-010). The default
     * is for the lists that belong to no project and so have nothing to reorder.
     */
    columns?: ListColumn[];
    /** Rows are numbered inside a project's sections and nowhere else, so the column follows them. */
    numbered?: boolean;
}>(), { numbered: true, columns: () => defaultListColumns });
</script>

<template>
    <div
        class="hidden items-stretch border-y border-border text-[11px] font-semibold tracking-wide text-muted-foreground uppercase md:flex"
        aria-hidden="true"
    >
        <span v-if="numbered" class="flex items-center" :class="[listColumns.index, listColumns.cell]">#</span>
        <span class="flex items-center" :class="[listColumns.name, listColumns.cell]">Task name</span>

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
