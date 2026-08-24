<script setup lang="ts">
/**
 * The top of a screen that is not a project: My Tasks, the Inbox, Search.
 *
 * Built to the same shape as `ProjectHeader` — a title row, then a row of tabs sitting on the
 * border — so the application reads as one place rather than as several screens that happen to
 * share a sidebar.
 *
 * The title is drawn rather than hidden. The sidebar says which screen you chose; a heading says
 * what you are looking at, and the two are not the same job — a person arriving from a link never
 * saw the sidebar highlight.
 */
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <header class="border-b border-border">
        <div class="flex flex-wrap items-center gap-3 px-4 pt-4 md:px-6" :class="$slots.tabs ? 'pb-3' : 'pb-4'">
            <div class="min-w-0">
                <h1 class="truncate text-xl font-semibold tracking-tight">{{ title }}</h1>
                <p v-if="description" class="mt-0.5 truncate text-sm text-muted-foreground">{{ description }}</p>
            </div>

            <div v-if="$slots.actions" class="ml-auto shrink-0">
                <slot name="actions" />
            </div>
        </div>

        <div v-if="$slots.tabs" class="px-2 md:px-4">
            <slot name="tabs" />
        </div>
    </header>
</template>
