<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import type { PageNode } from '@/modules/page/types';
import pageRoutes from '@/routes/pages';
import { show as showProject } from '@/routes/projects';

/**
 * Where this page sits, named rather than drawn.
 *
 * The sidebar answers the same question with indentation and disappears below `lg`; a nested page
 * on a phone would otherwise give a reader no way to know it is nested at all.
 *
 * Ancestors only — the page's own title is the heading underneath, and repeating it here would
 * make the trail end in the thing it is describing.
 */
const props = defineProps<{
    tree: PageNode[];
    pageId: string;
    project: { id: string; name: string };
}>();

/** The path from the root to the page, exclusive of the page itself. */
const ancestors = computed<PageNode[]>(() => {
    const walk = (nodes: PageNode[], trail: PageNode[]): PageNode[] | null => {
        for (const node of nodes) {
            if (node.id === props.pageId) {
                return trail;
            }

            const deeper = walk(node.children, [...trail, node]);

            if (deeper !== null) {
                return deeper;
            }
        }

        return null;
    };

    return walk(props.tree, []) ?? [];
});
</script>

<template>
    <nav aria-label="Breadcrumb" class="min-w-0">
        <ol class="flex min-w-0 items-center gap-1 text-sm text-muted-foreground">
            <li class="min-w-0 shrink-0">
                <Link
                    :href="showProject(project.id, { query: { view: 'pages' } }).url"
                    class="truncate transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                >{{ project.name }}</Link>
            </li>

            <li v-for="ancestor in ancestors" :key="ancestor.id" class="flex min-w-0 items-center gap-1">
                <ChevronRight class="size-3.5 shrink-0" aria-hidden="true" />

                <Link
                    :href="pageRoutes.show(ancestor.id).url"
                    class="truncate transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                >{{ ancestor.title }}</Link>
            </li>
        </ol>
    </nav>
</template>
