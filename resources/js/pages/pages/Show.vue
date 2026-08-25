<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { defineAsyncComponent, ref, watch } from 'vue';
import PageController from '@/actions/App/Http/Controllers/Page/PageController';
import { Button } from '@/components/ui/button';
import { useRealtime } from '@/composables/useRealtime';
import PageSaveState from '@/modules/page/components/PageSaveState.vue';
import PageTreeRow from '@/modules/page/components/PageTreeRow.vue';
import { usePageAutosave } from '@/modules/page/composables/usePageAutosave';
import type { PageDetail, PageDocument, PageNode, ProjectPages } from '@/modules/page/types';
import ProjectTile from '@/modules/project/components/ProjectTile.vue';
import pageRoutes from '@/routes/pages';
import { show as showProject } from '@/routes/projects';

/*
 * Tiptap and ProseMirror are a large chunk and only this screen has any use for them, so the
 * editor is asked for when a page is opened rather than downloaded by everybody who opens a
 * project (the arrangement `TaskDetailPanel` and the Quill editor already use).
 */
const PageEditor = defineAsyncComponent(() => import('@/modules/page/components/PageEditor.vue'));

/**
 * One page, written in.
 *
 * The document is the screen's own state while somebody is typing — the server is authoritative
 * about what is stored, and this is what has not been stored yet. Everything else on the screen
 * (the tree, the title, the project) comes from props and is replaced when the server answers.
 */
const props = defineProps<{
    page: PageDetail;
    project: { id: string; name: string; slug: string; color: string | null; icon: string | null };
    pages: ProjectPages;
}>();

const document = ref<PageDocument>(props.page.content);

const { state, save } = usePageAutosave(props.page.id, props.page.version);

/*
 * Somebody else changed a page in this project: the tree is refetched so a title or a new page
 * appears. The document is deliberately **not** in `only` — replacing it would take the caret
 * and the last sentence with it, and a save that lands after somebody else's is refused by the
 * version rather than by a refetch.
 */
useRealtime({
    channels: () => [`project.${props.project.id}`],
    only: ['pages'],
});

/** A different page arrived under the same component: start again from what the server sent. */
watch(
    () => props.page.id,
    () => (document.value = props.page.content),
);

const write = (next: PageDocument): void => {
    document.value = next;
    save(next);
};

const editable = (): boolean => props.pages.can.updatePage;

const addChild = (parent: PageNode): void => {
    router.post(PageController.store.url(props.project.id), { parent: parent.id });
};
</script>

<template>
    <div class="flex w-full">
        <Head :title="page.title" />

        <!-- The tree, beside the page rather than above it: what a document needs at hand is
             where it sits, and a sidebar keeps that visible while the page scrolls. -->
        <aside class="hidden w-64 shrink-0 border-r border-border py-4 lg:block">
            <div class="px-3 pb-2">
                <Link
                    :href="showProject(project.id, { query: { view: 'pages' } }).url"
                    class="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                >
                    <ArrowLeft class="size-4" aria-hidden="true" />
                    {{ project.name }}
                </Link>
            </div>

            <ul role="tree" aria-label="Pages in this project" class="list-none px-1">
                <PageTreeRow
                    v-for="node in pages.tree"
                    :key="node.id"
                    :page="node"
                    :depth="1"
                    :can="pages.can"
                    @add-child="addChild"
                    @rename="router.visit(pageRoutes.show($event.id).url)"
                    @remove="router.visit(pageRoutes.show($event.id).url)"
                />
            </ul>
        </aside>

        <div class="mx-auto flex w-full max-w-3xl flex-col px-4 py-6 md:px-8">
            <div class="flex items-center justify-between gap-3 pb-1">
                <Link
                    :href="showProject(project.id, { query: { view: 'pages' } }).url"
                    class="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none lg:hidden"
                >
                    <ProjectTile :name="project.name" :color="project.color" :icon="project.icon" class="size-5" />
                    {{ project.name }}
                </Link>

                <PageSaveState :state="state" class="ml-auto" />
            </div>

            <h1 class="pb-4 text-2xl font-semibold tracking-tight text-foreground">{{ page.title }}</h1>

            <!-- A conflict stops the editor rather than letting somebody keep writing into a
                 copy that can no longer be saved. Reloading is the only honest way out of it
                 until pages are edited together (ADR-0017). -->
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

            <PageEditor
                :key="page.id"
                :model-value="document"
                :editable="editable() && state !== 'conflict'"
                @update:model-value="write"
            />
        </div>
    </div>
</template>
