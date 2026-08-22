<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import type { ProjectSummary } from '@/modules/project/types';
import { create, edit } from '@/routes/projects';

defineProps<{
    projects: ProjectSummary[];
    can: { create: boolean };
}>();
</script>

<template>
    <div class="flex flex-col space-y-6">
        <Head title="Projects" />

        <Heading title="Projects" description="Everything you can reach in this workspace" />

        <div v-if="projects.length" class="flex flex-col divide-y rounded-lg border">
            <Link
                v-for="project in projects"
                :key="project.id"
                :href="edit(project.id).url"
                class="px-4 py-3 text-sm hover:bg-accent"
            >
                {{ project.name }}
            </Link>
        </div>

        <p v-else class="text-sm text-muted-foreground">No projects yet.</p>

        <Button v-if="can.create" as-child class="self-start">
            <Link :href="create().url">New project</Link>
        </Button>
    </div>
</template>
