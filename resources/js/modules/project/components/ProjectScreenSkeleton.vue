<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Skeleton } from '@/components/ui/skeleton';
import ProjectTile from '@/modules/project/components/ProjectTile.vue';
import ProjectViewSkeleton from '@/modules/project/components/ProjectViewSkeleton.vue';
import { landingView } from '@/modules/project/landingView';
import type { SidebarProject } from '@/modules/project/types';
import { show } from '@/routes/projects';

/**
 * A project on its way in, drawn the moment somebody chooses it.
 *
 * The sidebar already knows the project's name, colour and icon, so the header is the real one
 * from the first frame and only what the server has to read pulses. A project the capped sidebar
 * does not list — one opened from the project index — gets a placeholder name instead.
 */
const page = usePage();

const address = computed<URL>(() => new URL(page.url, window.location.origin));

const project = computed<SidebarProject | null>(
    () =>
        page.props.projects.find(
            (candidate) => show(candidate.id).url === address.value.pathname,
        ) ?? null,
);

const view = computed<string>(
    () =>
        address.value.searchParams.get('view') ??
        landingView(address.value.pathname) ??
        'list',
);

const tabWidths = ['w-10', 'w-12', 'w-16', 'w-10', 'w-12'];
</script>

<template>
    <div class="flex flex-col">
        <Head v-if="project" :title="project.name" />

        <div class="border-b border-border">
            <div
                class="flex flex-wrap items-center gap-3 px-4 pt-4 pb-3 md:px-6"
            >
                <template v-if="project">
                    <ProjectTile
                        :name="project.name"
                        :color="project.color"
                        :icon="project.icon"
                        size="lg"
                    />
                    <span
                        class="min-w-0 truncate text-xl font-semibold tracking-tight"
                        >{{ project.name }}</span
                    >
                </template>

                <template v-else>
                    <Skeleton class="size-9 rounded-lg" />
                    <Skeleton class="h-6 w-48" />
                </template>

                <div
                    class="ml-auto flex shrink-0 items-center gap-3"
                    aria-hidden="true"
                >
                    <Skeleton class="h-7 w-20 rounded-full" />
                    <Skeleton class="h-8 w-16" />
                </div>
            </div>

            <div
                class="flex h-9 items-end gap-1 px-2 md:px-4"
                aria-hidden="true"
            >
                <span
                    v-for="(width, index) in tabWidths"
                    :key="index"
                    class="px-3 pb-2.5"
                >
                    <Skeleton class="h-4" :class="width" />
                </span>
            </div>
        </div>

        <div
            v-if="view !== 'files' && view !== 'pages'"
            class="flex items-center gap-2 px-4 py-3 md:px-6"
            aria-hidden="true"
        >
            <Skeleton class="h-8 w-24" />
            <Skeleton class="h-8 w-20" />
        </div>

        <ProjectViewSkeleton :view="view" />
    </div>
</template>
