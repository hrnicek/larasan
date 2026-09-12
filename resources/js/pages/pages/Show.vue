<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { ref } from 'vue';
import { useRealtime } from '@/composables/useRealtime';
import PageDocumentPane from '@/modules/page/components/PageDocumentPane.vue';
import PageTreePanel from '@/modules/page/components/PageTreePanel.vue';
import type { PageDetail, ProjectPages } from '@/modules/page/types';
import { show as showProject } from '@/routes/projects';

const props = defineProps<{
    page: PageDetail;
    project: { id: string; name: string; slug: string; color: string | null; icon: string | null };
    pages: ProjectPages;
}>();

// The document stays out of `only` so a reload never steals the caret; concurrent saves are refused by version.
useRealtime({
    channels: () => [`project.${props.project.id}`],
    only: ['pages'],
});

// Visits that keep state (creating a page lands on it) reuse this component, so the pane is keyed to rebuild its autosave.
const reloads = ref(0);

const reload = (): void => {
    router.reload({ onSuccess: () => reloads.value++ });
};
</script>

<template>
    <div class="flex w-full">
        <Head :title="page.title" />

        <aside class="hidden w-72 shrink-0 border-r border-border py-4 lg:block">
            <div class="px-3 pb-2">
                <Link
                    :href="showProject(project.id, { query: { view: 'pages' } }).url"
                    component="projects/Show"
                    prefetch="click"
                    class="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                >
                    <ArrowLeft class="size-4" aria-hidden="true" />
                    {{ project.name }}
                </Link>
            </div>

            <PageTreePanel
                :project-id="project.id"
                :pages="pages"
                :current-id="page.id"
                variant="sidebar"
                class="px-1"
            />
        </aside>

        <PageDocumentPane
            :key="`${page.id}:${reloads}`"
            :page="page"
            :project="project"
            :pages="pages"
            @reload="reload"
        />
    </div>
</template>
