<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { FileText, Plus } from '@lucide/vue';
import { ref } from 'vue';
import PageController from '@/actions/App/Http/Controllers/Page/PageController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import { Button } from '@/components/ui/button';
import PageRenameDialog from '@/modules/page/components/PageRenameDialog.vue';
import PageTreeRow from '@/modules/page/components/PageTreeRow.vue';
import type { PageNode, ProjectPages } from '@/modules/page/types';

/**
 * The documents written inside this project, as the tree they are.
 *
 * A tree rather than a table: what a reader needs from a list of pages is where each one sits,
 * and depth is the only column that answers that. The rest — who wrote it, when — is one line of
 * context per row, not six.
 *
 * Nothing here is optimistic. Creating and removing a page are server answers, and a tree that
 * redrew itself first would have to guess where the new page landed.
 */
const props = defineProps<{
    projectId: string;
    pages: ProjectPages;
}>();

const renaming = ref<PageNode | null>(null);
const removing = ref<PageNode | null>(null);

const addPage = (parent?: PageNode): void => {
    router.post(
        PageController.store.url(props.projectId),
        parent ? { parent: parent.id } : {},
        { preserveScroll: true },
    );
};

const remove = (): void => {
    if (removing.value === null) {
        return;
    }

    router.delete(PageController.destroy.url(removing.value.id), {
        preserveScroll: true,
        onFinish: () => (removing.value = null),
    });
};
</script>

<template>
    <div class="flex flex-col gap-3 px-4 pt-4 md:px-6">
        <div v-if="pages.tree.length" class="flex flex-col gap-2">
            <div v-if="pages.can.createPage" class="flex justify-end">
                <Button variant="outline" size="sm" @click="addPage()">
                    <Plus class="size-4" />
                    New page
                </Button>
            </div>

            <ul role="tree" aria-label="Pages" class="list-none">
                <PageTreeRow
                    v-for="page in pages.tree"
                    :key="page.id"
                    :page="page"
                    :depth="1"
                    :can="pages.can"
                    @add-child="addPage($event)"
                    @rename="renaming = $event"
                    @remove="removing = $event"
                />
            </ul>
        </div>

        <EmptyState
            v-else
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
