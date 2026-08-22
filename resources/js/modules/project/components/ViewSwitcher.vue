<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { show } from '@/routes/projects';

/**
 * The view lives in the URL rather than in local state, so a reload and a shared link both
 * show what the sender saw. Each option is a real link for the same reason.
 */
defineProps<{
    projectId: string;
    current: string;
    views: string[];
}>();
</script>

<template>
    <nav class="inline-flex rounded-md border p-0.5" aria-label="View">
        <Link
            v-for="view in views"
            :key="view"
            :href="show(projectId, { query: { view } }).url"
            :aria-current="view === current ? 'page' : undefined"
            class="rounded px-3 py-1 text-sm capitalize"
            :class="view === current ? 'bg-accent text-accent-foreground' : 'text-muted-foreground hover:text-foreground'"
        >
            {{ view }}
        </Link>
    </nav>
</template>
