<script setup lang="ts">
import { listColumns } from '@/modules/task/listColumns';

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
 */
withDefaults(defineProps<{
    fields?: { id: string; name: string; type: string }[];
    /** Rows are numbered inside a project's sections and nowhere else, so the column follows them. */
    numbered?: boolean;
}>(), { numbered: true });
</script>

<template>
    <div
        class="hidden items-stretch border-y border-border text-[11px] font-semibold tracking-wide text-muted-foreground uppercase md:flex"
        aria-hidden="true"
    >
        <span v-if="numbered" class="flex items-center" :class="[listColumns.index, listColumns.cell]">#</span>
        <span class="flex items-center" :class="[listColumns.name, listColumns.cell]">Task name</span>

        <span
            v-for="field in fields"
            :key="field.id"
            class="flex items-center"
            :class="[listColumns.field, listColumns.cell]"
            :title="field.name"
        >
            {{ field.name }}
        </span>

        <span class="flex items-center" :class="[listColumns.assignee, listColumns.cell]">Assignee</span>
        <span class="flex items-center" :class="[listColumns.due, listColumns.cell]">Due</span>
        <span class="flex items-center" :class="[listColumns.priority, listColumns.cell]">Priority</span>
        <span :class="listColumns.filler" />
    </div>
</template>
