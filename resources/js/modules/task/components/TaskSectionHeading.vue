<script setup lang="ts">
defineProps<{
    title: string;
    count?: string | null;
}>();
</script>

<template>
    <div class="flex min-h-8 items-center gap-2">
        <h3 class="text-sm font-semibold text-foreground">{{ title }}</h3>

        <span
            v-if="count"
            class="rounded bg-muted px-1.5 py-0.5 text-[11px] font-medium text-muted-foreground tabular-nums"
        >
            {{ count }}
        </span>

        <slot name="add" />

        <div class="ml-auto flex items-center gap-1">
            <slot name="actions" />
        </div>
    </div>
</template>
