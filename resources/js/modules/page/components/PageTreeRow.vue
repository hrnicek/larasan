<script setup lang="ts">
import { ChevronRight, FileText, MoreHorizontal, Plus } from '@lucide/vue';
import { ref } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatFeedTime, fullFeedTime } from '@/lib/feedTime';
import type { PageNode } from '@/modules/page/types';

/**
 * One document in the tree, and whatever is written underneath it.
 *
 * Recursive, because the thing being drawn is: a page holds pages, bounded at five levels by the
 * server. The indent is the only thing that says which level a row is on, so it is also carried
 * by `aria-level` — a screen reader has no indent to look at.
 */
const props = defineProps<{
    page: PageNode;
    depth: number;
    can: { createPage: boolean; updatePage: boolean; deletePage: boolean };
}>();

const emit = defineEmits<{
    addChild: [page: PageNode];
    rename: [page: PageNode];
    remove: [page: PageNode];
}>();

/** Open by default: a tree that hides what is in it makes somebody click to find out it is empty. */
const expanded = ref(true);

const hasChildren = (): boolean => props.page.children.length > 0;
</script>

<template>
    <li role="none" class="list-none">
        <div
            role="treeitem"
            :aria-level="depth"
            :aria-expanded="hasChildren() ? expanded : undefined"
            class="group flex items-center gap-1.5 rounded-md py-1.5 pr-1 text-sm transition-colors hover:bg-accent"
            :style="{ paddingLeft: `${(depth - 1) * 16 + 4}px` }"
        >
            <button
                v-if="hasChildren()"
                type="button"
                class="grid size-5 shrink-0 place-items-center rounded text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                :aria-label="expanded ? `Collapse ${page.title}` : `Expand ${page.title}`"
                @click="expanded = !expanded"
            >
                <ChevronRight class="size-3.5 transition-transform" :class="expanded && 'rotate-90'" />
            </button>

            <span v-else class="size-5 shrink-0" aria-hidden="true" />

            <FileText class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />

            <span class="min-w-0 flex-1 truncate font-medium text-foreground">{{ page.title }}</span>

            <span
                v-if="page.excerpt"
                class="hidden min-w-0 flex-1 truncate text-muted-foreground md:block"
            >{{ page.excerpt }}</span>

            <time
                v-if="page.updatedAt"
                :datetime="page.updatedAt"
                :title="fullFeedTime(page.updatedAt)"
                class="hidden shrink-0 text-xs text-muted-foreground sm:block"
            >{{ formatFeedTime(page.updatedAt) }}</time>

            <!-- The controls appear on hover and on focus. Focus is the half that is usually
                 forgotten, and without it the menu is unreachable from the keyboard. -->
            <div class="flex shrink-0 items-center opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100">
                <button
                    v-if="can.createPage && depth < 5"
                    type="button"
                    class="grid size-6 place-items-center rounded text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                    :aria-label="`Add a page inside ${page.title}`"
                    @click="emit('addChild', page)"
                >
                    <Plus class="size-4" />
                </button>

                <DropdownMenu v-if="can.updatePage || can.deletePage">
                    <DropdownMenuTrigger
                        class="grid size-6 place-items-center rounded text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                        :aria-label="`Actions for ${page.title}`"
                    >
                        <MoreHorizontal class="size-4" />
                    </DropdownMenuTrigger>

                    <DropdownMenuContent align="end">
                        <DropdownMenuItem v-if="can.updatePage" @select="emit('rename', page)">
                            Rename
                        </DropdownMenuItem>
                        <DropdownMenuItem v-if="can.deletePage" variant="destructive" @select="emit('remove', page)">
                            Delete
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>

        <ul v-if="expanded && hasChildren()" role="group" class="list-none">
            <PageTreeRow
                v-for="child in page.children"
                :key="child.id"
                :page="child"
                :depth="depth + 1"
                :can="can"
                @add-child="emit('addChild', $event)"
                @rename="emit('rename', $event)"
                @remove="emit('remove', $event)"
            />
        </ul>
    </li>
</template>
