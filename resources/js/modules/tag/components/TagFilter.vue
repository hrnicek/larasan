<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { accentTextClass } from '@/lib/accentColor';
import type { TaskTag } from '@/modules/task/types';
import { show } from '@/routes/projects';

/**
 * Narrowing a board or a list to what it is about.
 *
 * Every option is a real link, for the reason the view switcher's are: a filtered view is
 * something people send each other, so it has to be an address. Picking a second tag narrows
 * rather than widens — the server matches cards carrying *all* of them.
 */
const props = defineProps<{
    projectId: string;
    view: string;
    active: string[];
    available: TaskTag[];
}>();

/** The URL this tag would lead to: on if it is off, off if it is on. */
const toggled = (tag: TaskTag): string => {
    const next = props.active.includes(tag.id)
        ? props.active.filter((id) => id !== tag.id)
        : [...props.active, tag.id];

    return show(props.projectId, { query: { view: props.view, tags: next } }).url;
};
</script>

<template>
    <nav v-if="available.length" class="flex flex-wrap items-center gap-2" aria-label="Filter by tag">
        <Link
            v-for="tag in available"
            :key="tag.id"
            :href="toggled(tag)"
            :aria-pressed="active.includes(tag.id)"
            class="rounded border px-1.5 py-0.5 text-xs"
            :class="[accentTextClass(tag.color), active.includes(tag.id) ? 'border-current' : 'border-input opacity-70']"
        >
            {{ tag.name }}
        </Link>

        <Link
            v-if="active.length"
            :href="show(projectId, { query: { view } }).url"
            class="text-xs text-muted-foreground underline"
        >
            Clear
        </Link>
    </nav>
</template>
