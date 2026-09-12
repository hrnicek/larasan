<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, PanelLeft } from '@lucide/vue';
import { defineAsyncComponent, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { useRealtime } from '@/composables/useRealtime';
import { formatFeedTime, fullFeedTime } from '@/lib/feedTime';
import PageBreadcrumb from '@/modules/page/components/PageBreadcrumb.vue';
import PageSaveState from '@/modules/page/components/PageSaveState.vue';
import PageTitleField from '@/modules/page/components/PageTitleField.vue';
import PageTreePanel from '@/modules/page/components/PageTreePanel.vue';
import { usePageAutosave } from '@/modules/page/composables/usePageAutosave';
import type { PageDetail, PageDocument, ProjectPages } from '@/modules/page/types';
import ProjectTile from '@/modules/project/components/ProjectTile.vue';
import { show as showProject } from '@/routes/projects';

const PageEditor = defineAsyncComponent(() => import('@/modules/page/components/PageEditor.vue'));

const props = defineProps<{
    page: PageDetail;
    project: { id: string; name: string; slug: string; color: string | null; icon: string | null };
    pages: ProjectPages;
}>();

const document = ref<PageDocument>(props.page.content);

const { state, save } = usePageAutosave(props.page.id, props.page.version);

// The document stays out of `only` so a reload never steals the caret; concurrent saves are refused by version.
useRealtime({
    channels: () => [`project.${props.project.id}`],
    only: ['pages'],
});

watch(
    () => props.page.id,
    () => (document.value = props.page.content),
);

const write = (next: PageDocument): void => {
    document.value = next;
    save(next);
};

const editable = (): boolean => props.pages.can.updatePage;

const body = ref<{ focus: () => void } | null>(null);

const empty = (): boolean => {
    const blocks = document.value.content;

    return !Array.isArray(blocks) || blocks.length === 0;
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

        <div class="mx-auto flex w-full max-w-3xl flex-col px-4 py-6 md:px-8">
            <div class="flex items-center justify-between gap-3 pb-2">
                <Sheet>
                    <SheetTrigger
                        class="inline-flex items-center gap-1.5 rounded-md text-sm text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none lg:hidden"
                    >
                        <PanelLeft class="size-4" aria-hidden="true" />
                        <ProjectTile :name="project.name" :color="project.color" :icon="project.icon" class="size-5" />
                        <span class="truncate">{{ project.name }}</span>
                    </SheetTrigger>

                    <SheetContent side="left" class="w-80 overflow-y-auto p-0">
                        <SheetHeader class="px-4 pt-4 pb-2">
                            <SheetTitle>Pages</SheetTitle>
                        </SheetHeader>

                        <PageTreePanel
                            :project-id="project.id"
                            :pages="pages"
                            :current-id="page.id"
                            variant="sidebar"
                            class="px-2 pb-6"
                        />
                    </SheetContent>
                </Sheet>

                <PageSaveState :state="state" class="ml-auto" />
            </div>

            <PageBreadcrumb
                :tree="pages.tree"
                :page-id="page.id"
                :project="project"
                class="pb-2 lg:hidden"
            />

            <PageTitleField
                :page-id="page.id"
                :title="page.title"
                :editable="editable()"
                @done="body?.focus()"
            />

            <p v-if="page.updatedAt" class="pb-4 text-xs text-muted-foreground">
                Last changed
                <time :datetime="page.updatedAt" :title="fullFeedTime(page.updatedAt)">
                    {{ formatFeedTime(page.updatedAt) }}
                </time>
                <template v-if="page.updatedBy"> by {{ page.updatedBy }}</template>
            </p>

            <div
                v-if="state === 'conflict'"
                class="mb-4 flex items-center justify-between gap-3 rounded-lg border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm"
            >
                <p class="text-foreground">
                    Somebody else saved this page while you were writing. Reload to see their version — anything you
                    typed since is only in this window.
                </p>

                <Button size="sm" variant="outline" @click="router.reload()">Reload</Button>
            </div>

            <p v-if="!editable() && empty()" class="text-sm text-muted-foreground">
                Nothing has been written on this page yet.
            </p>

            <PageEditor
                v-else
                :key="page.id"
                ref="body"
                :model-value="document"
                :editable="editable() && state !== 'conflict'"
                @update:model-value="write"
            />
        </div>
    </div>
</template>
