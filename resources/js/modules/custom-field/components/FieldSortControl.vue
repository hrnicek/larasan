<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { ListFieldColumn } from '@/modules/task/types';
import { show } from '@/routes/projects';

const props = defineProps<{
    projectId: string;
    view: string;
    fields: ListFieldColumn[];
    sort: { field: string | null; direction: string; filters: Record<string, string> };
}>();

const next = (field: ListFieldColumn): string => {
    const query: Record<string, string> = { view: props.view };

    if (props.sort.field !== field.id) {
        return show(props.projectId, { query: { ...query, sort: field.id, direction: 'asc' } }).url;
    }

    if (props.sort.direction === 'asc') {
        return show(props.projectId, { query: { ...query, sort: field.id, direction: 'desc' } }).url;
    }

    return show(props.projectId, { query }).url;
};

const marker = (field: ListFieldColumn): string => {
    if (props.sort.field !== field.id) {
        return '';
    }

    return props.sort.direction === 'desc' ? ' ↓' : ' ↑';
};
</script>

<template>
    <nav class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground" aria-label="Sort by field">
        <span>Sort:</span>

        <Link
            v-for="field in fields"
            :key="field.id"
            :href="next(field)"
            :aria-current="sort.field === field.id ? 'true' : undefined"
            class="rounded border border-input px-1.5 py-0.5"
            :class="sort.field === field.id ? 'text-foreground' : ''"
        >
            {{ field.name }}{{ marker(field) }}
        </Link>
    </nav>
</template>
