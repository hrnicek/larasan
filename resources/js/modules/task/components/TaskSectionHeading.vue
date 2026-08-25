<script setup lang="ts">
/**
 * The heading of one block on the task detail: what it is, how much of it there is, and the
 * controls that belong to it.
 *
 * Projects, subtasks and attachments are three blocks that have to line up with one another.
 * Three headings built by hand line up until one of them is edited.
 */
defineProps<{
    title: string;
    /** A badge beside the name — `3` for a count, `0 / 2` for a proportion. */
    count?: string | null;
}>();
</script>

<template>
    <div class="flex min-h-8 items-center gap-2">
        <h3 class="text-sm font-semibold text-foreground">{{ title }}</h3>

        <span
            v-if="count"
            class="rounded bg-muted px-1.5 py-0.5 text-[11px] font-medium tabular-nums text-muted-foreground"
        >
            {{ count }}
        </span>

        <slot name="add" />

        <div class="ml-auto flex items-center gap-1">
            <slot name="actions" />
        </div>
    </div>
</template>
