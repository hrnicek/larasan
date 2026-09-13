<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { FileText, Plus } from '@lucide/vue';
import { ref } from 'vue';
import PageController from '@/actions/App/Http/Controllers/Page/PageController';
import PagePlacementController from '@/actions/App/Http/Controllers/Page/PagePlacementController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import { Button } from '@/components/ui/button';
import PageRenameDialog from '@/modules/page/components/PageRenameDialog.vue';
import PageTreeRow from '@/modules/page/components/PageTreeRow.vue';
import { placementFor } from '@/modules/page/lib/movePage';
import type { MoveDirection } from '@/modules/page/lib/movePage';
import type { PageNode, ProjectPages } from '@/modules/page/types';
import { show as showProject } from '@/routes/projects';

const props = withDefaults(
    defineProps<{
        projectId: string;
        pages: ProjectPages;
        currentId?: string | null;
        variant?: 'view' | 'sidebar';
    }>(),
    { currentId: null, variant: 'view' },
);

const renaming = ref<PageNode | null>(null);
const removing = ref<PageNode | null>(null);

const addPage = (parent?: PageNode): void => {
    router.post(
        PageController.store.url(props.projectId),
        parent ? { parent: parent.id } : {},
        { preserveScroll: true },
    );
};

const move = ({
    page,
    direction,
}: {
    page: PageNode;
    direction: MoveDirection;
}): void => {
    const placement = placementFor(props.pages.tree, page.id, direction);

    if (placement === null) {
        return;
    }

    router.put(PagePlacementController.update.url(page.id), placement, {
        preserveScroll: true,
    });
};

const holds = (node: PageNode, id: string): boolean =>
    node.id === id || node.children.some((child) => holds(child, id));

const remove = (): void => {
    const page = removing.value;

    if (page === null) {
        return;
    }

    // The redirect back would land on a deleted page when it holds the one being read.
    const readingItsOwnGrave =
        props.currentId !== null && holds(page, props.currentId);

    router.delete(PageController.destroy.url(page.id), {
        preserveScroll: true,
        onFinish: () => (removing.value = null),
        onSuccess: () => {
            if (readingItsOwnGrave) {
                router.visit(
                    showProject(props.projectId, { query: { view: 'pages' } })
                        .url,
                );
            }
        },
    });
};
</script>

<template>
    <div class="flex min-w-0 flex-col gap-2">
        <div
            v-if="
                variant === 'view' && pages.can.createPage && pages.tree.length
            "
            class="flex justify-end"
        >
            <Button variant="outline" size="sm" @click="addPage()">
                <Plus class="size-4" />
                New page
            </Button>
        </div>

        <div v-if="variant === 'sidebar' && pages.can.createPage" class="px-2">
            <Button
                variant="ghost"
                size="sm"
                class="w-full justify-start text-muted-foreground"
                @click="addPage()"
            >
                <Plus class="size-4" />
                New page
            </Button>
        </div>

        <ul
            v-if="pages.tree.length"
            role="tree"
            aria-label="Pages"
            class="list-none"
        >
            <PageTreeRow
                v-for="(page, index) in pages.tree"
                :key="page.id"
                :page="page"
                :depth="1"
                :can="pages.can"
                :current-id="currentId"
                :moves="{
                    up: index > 0,
                    down: index < pages.tree.length - 1,
                    in: index > 0,
                    out: false,
                }"
                @add-child="addPage($event)"
                @rename="renaming = $event"
                @remove="removing = $event"
                @move="move"
            />
        </ul>

        <EmptyState
            v-else-if="variant === 'view'"
            :icon="FileText"
            title="No pages yet"
            description="A page is where the brief, the notes and the decisions live — beside the work rather than inside a task."
        >
            <template #action>
                <Button v-if="pages.can.createPage" @click="addPage()">
                    <Plus class="size-4" />
                    Write the first page
                </Button>
            </template>
        </EmptyState>

        <PageRenameDialog
            v-if="renaming"
            :open="renaming !== null"
            :page="renaming"
            @update:open="(open) => !open && (renaming = null)"
        />

        <ConfirmDialog
            :open="removing !== null"
            title="Delete this page?"
            :description="`“${removing?.title}” and everything written inside it will be removed.`"
            confirm-label="Delete page"
            @update:open="(open) => !open && (removing = null)"
            @confirm="remove"
        />
    </div>
</template>
