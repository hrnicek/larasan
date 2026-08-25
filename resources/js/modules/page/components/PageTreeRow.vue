<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight, FileText, MoreHorizontal, Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { PageNode } from '@/modules/page/types';
import pageRoutes from '@/routes/pages';

/**
 * One document in the tree, and whatever is written underneath it.
 *
 * Recursive, because the thing being drawn is: a page holds pages, bounded at five levels by the
 * server. The indent is the only thing that says which level a row is on, so it is also carried
 * by `aria-level` — a screen reader has no indent to look at.
 *
 * The row is a **link**, not a button that navigates: a page has an address, and middle-click,
 * ⌘-click and "copy link" are things people do to documents.
 */
const props = defineProps<{
    page: PageNode;
    depth: number;
    can: { createPage: boolean; updatePage: boolean; deletePage: boolean };
    /** The page being read, so the tree can say where the reader is. */
    currentId?: string | null;
    /** What this row may do about its own position, decided by where it sits among its siblings. */
    moves?: { up: boolean; down: boolean; in: boolean; out: boolean };
}>();

const emit = defineEmits<{
    addChild: [page: PageNode];
    rename: [page: PageNode];
    remove: [page: PageNode];
    move: [payload: { page: PageNode; direction: 'up' | 'down' | 'in' | 'out' }];
}>();

const hasChildren = computed<boolean>(() => props.page.children.length > 0);

const isCurrent = computed<boolean>(() => props.currentId === props.page.id);

/** Whether the page being read is somewhere underneath this one. */
const holdsCurrent = (node: PageNode): boolean =>
    node.children.some((child) => child.id === props.currentId || holdsCurrent(child));

/*
 * Open by default — a tree that hides what is in it makes somebody click to find out it is empty
 * — and forced open when the page being read is inside it, because a reader should never have to
 * find their own position.
 */
const expanded = ref(true);

watch(
    () => props.currentId,
    () => {
        if (holdsCurrent(props.page)) {
            expanded.value = true;
        }
    },
    { immediate: true },
);

const movable = computed(() => props.moves ?? { up: false, down: false, in: false, out: false });

const canMove = computed<boolean>(
    () => movable.value.up || movable.value.down || movable.value.in || movable.value.out,
);
</script>

<template>
    <li role="none" class="list-none">
        <div
            role="treeitem"
            :aria-level="depth"
            :aria-expanded="hasChildren ? expanded : undefined"
            :aria-current="isCurrent ? 'page' : undefined"
            class="group flex items-center gap-1 rounded-md pr-1 text-sm transition-colors"
            :class="isCurrent ? 'bg-accent' : 'hover:bg-accent/60'"
            :style="{ paddingLeft: `${(depth - 1) * 14 + 2}px` }"
        >
            <button
                v-if="hasChildren"
                type="button"
                class="grid size-5 shrink-0 place-items-center rounded text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                :aria-label="expanded ? `Collapse ${page.title}` : `Expand ${page.title}`"
                @click="expanded = !expanded"
            >
                <ChevronRight class="size-3.5 transition-transform" :class="expanded && 'rotate-90'" />
            </button>

            <span v-else class="size-5 shrink-0" aria-hidden="true" />

            <Link
                :href="pageRoutes.show(page.id).url"
                class="flex min-w-0 flex-1 items-center gap-2 py-1.5 focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
            >
                <FileText
                    class="size-4 shrink-0"
                    :class="isCurrent ? 'text-foreground' : 'text-muted-foreground'"
                    aria-hidden="true"
                />

                <span
                    class="min-w-0 flex-1 truncate"
                    :class="isCurrent ? 'font-medium text-foreground' : 'text-foreground'"
                >{{ page.title }}</span>

                <span
                    v-if="page.excerpt"
                    class="hidden min-w-0 flex-[2] truncate text-muted-foreground lg:block"
                >{{ page.excerpt }}</span>
            </Link>

            <!-- The controls appear on hover and on focus. Focus is the half usually forgotten,
                 and without it the menu is unreachable from the keyboard. They stay visible on
                 the row being read, which is the one a person is most likely to act on. -->
            <div
                class="flex shrink-0 items-center transition-opacity group-hover:opacity-100 group-focus-within:opacity-100"
                :class="isCurrent ? 'opacity-100' : 'opacity-0'"
            >
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

                    <DropdownMenuContent align="end" class="w-52">
                        <DropdownMenuItem v-if="can.updatePage" @select="emit('rename', page)">
                            Rename
                        </DropdownMenuItem>

                        <!-- Moving lives in the menu because dragging cannot be the only way:
                             every drag in this application has a keyboard route to the same
                             result (`docs/ui/design-system.md`). An option that cannot apply is
                             absent rather than present and refused. -->
                        <template v-if="can.updatePage && canMove">
                            <DropdownMenuSeparator />

                            <DropdownMenuItem v-if="movable.up" @select="emit('move', { page, direction: 'up' })">
                                Move up
                            </DropdownMenuItem>
                            <DropdownMenuItem v-if="movable.down" @select="emit('move', { page, direction: 'down' })">
                                Move down
                            </DropdownMenuItem>
                            <DropdownMenuItem v-if="movable.in" @select="emit('move', { page, direction: 'in' })">
                                Move inside the page above
                            </DropdownMenuItem>
                            <DropdownMenuItem v-if="movable.out" @select="emit('move', { page, direction: 'out' })">
                                Move out one level
                            </DropdownMenuItem>
                        </template>

                        <template v-if="can.deletePage">
                            <DropdownMenuSeparator />
                            <DropdownMenuItem variant="destructive" @select="emit('remove', page)">
                                Delete
                            </DropdownMenuItem>
                        </template>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>

        <ul v-if="expanded && hasChildren" role="group" class="list-none">
            <PageTreeRow
                v-for="(child, index) in page.children"
                :key="child.id"
                :page="child"
                :depth="depth + 1"
                :can="can"
                :current-id="currentId"
                :moves="{
                    up: index > 0,
                    down: index < page.children.length - 1,
                    in: index > 0 && depth + 1 < 5,
                    out: true,
                }"
                @add-child="emit('addChild', $event)"
                @rename="emit('rename', $event)"
                @remove="emit('remove', $event)"
                @move="emit('move', $event)"
            />
        </ul>
    </li>
</template>
