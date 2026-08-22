<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { accentTextClass } from '@/lib/accentColor';
import ViewSwitcher from '@/modules/project/components/ViewSwitcher.vue';
import { edit } from '@/routes/projects';

defineProps<{
    project: { id: string; name: string; color: string | null; archived: boolean };
    view: string;
    views: string[];
}>();
</script>

<template>
    <header class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <h1 class="text-xl font-semibold" :class="accentTextClass(project.color)">
                {{ project.name }}
            </h1>
            <span v-if="project.archived" class="rounded bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                Archived
            </span>
        </div>

        <div class="flex items-center gap-3">
            <ViewSwitcher :project-id="project.id" :current="view" :views="views" />
            <Link :href="edit(project.id).url" class="text-sm text-muted-foreground hover:text-foreground">
                Settings
            </Link>
        </div>
    </header>
</template>
