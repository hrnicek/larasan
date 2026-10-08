<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Skeleton } from '@/components/ui/skeleton';
import ProjectTile from '@/modules/project/components/ProjectTile.vue';
import type { SidebarProject } from '@/modules/project/types';
import { edit } from '@/routes/projects';

const page = usePage();

const project = computed<SidebarProject | null>(() => {
    const path = new URL(page.url, window.location.origin).pathname;

    return (
        page.props.projects.find(
            (candidate) => edit(candidate.id).url === path,
        ) ?? null
    );
});

const railWidths = ['w-20', 'w-24', 'w-16', 'w-28', 'w-20'];
const sections = [3, 2];
</script>

<template>
    <div class="flex flex-col">
        <Head v-if="project" :title="`${project.name} settings`" />

        <div class="border-b border-border">
            <div class="flex flex-wrap items-center gap-3 px-4 py-4 md:px-6">
                <template v-if="project">
                    <ProjectTile
                        :name="project.name"
                        :color="project.color"
                        :icon="project.icon"
                        size="lg"
                    />

                    <div class="min-w-0">
                        <p
                            class="truncate text-xl font-semibold tracking-tight"
                        >
                            {{ project.name }}
                        </p>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            Project settings
                        </p>
                    </div>
                </template>

                <template v-else>
                    <Skeleton class="size-9 rounded-lg" />
                    <Skeleton class="h-6 w-48" />
                </template>

                <Skeleton class="ml-auto h-8 w-36" aria-hidden="true" />
            </div>
        </div>

        <div
            class="mx-auto w-full max-w-5xl px-4 py-8 md:px-6"
            aria-hidden="true"
        >
            <div class="flex flex-col gap-8 lg:flex-row lg:gap-12">
                <div
                    class="flex flex-row gap-2 lg:w-44 lg:shrink-0 lg:flex-col"
                >
                    <Skeleton
                        v-for="(width, index) in railWidths"
                        :key="index"
                        class="h-4"
                        :class="width"
                    />
                </div>

                <div class="min-w-0 flex-1 space-y-10">
                    <div
                        v-for="(fields, index) in sections"
                        :key="index"
                        class="space-y-4"
                    >
                        <Skeleton class="h-5 w-36" />

                        <div
                            v-for="field in fields"
                            :key="field"
                            class="grid gap-2"
                        >
                            <Skeleton class="h-4 w-24" />
                            <Skeleton class="h-9 w-full" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
