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

const props = defineProps<{
    page: PageNode;
    depth: number;
    can: { createPage: boolean; updatePage: boolean; deletePage: boolean };
    currentId?: string | null;
    moves?: { up: boolean; down: boolean; in: boolean; out: boolean };
}>();

const emit = defineEmits<{
    addChild: [page: PageNode];
    rename: [page: PageNode];
    remove: [page: PageNode];
    move: [
        payload: { page: PageNode; direction: 'up' | 'down' | 'in' | 'out' },
    ];
}>();

const hasChildren = computed<boolean>(() => props.page.children.length > 0);

const isCurrent = computed<boolean>(() => props.currentId === props.page.id);

const holdsCurrent = (node: PageNode): boolean =>
    node.children.some(
        (child) => child.id === props.currentId || holdsCurrent(child),
    );

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

const movable = computed(
    () => props.moves ?? { up: false, down: false, in: false, out: false },
);

const canMove = computed<boolean>(
    () =>
        movable.value.up ||
        movable.value.down ||
        movable.value.in ||
        movable.value.out,
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
                :aria-label="
                    expanded ? `Collapse ${page.title}` : `Expand ${page.title}`
                "
                @click="expanded = !expanded"
            >
                <ChevronRight
                    class="size-3.5 transition-transform"
                    :class="expanded && 'rotate-90'"
                />
            </button>

            <span v-else class="size-5 shrink-0" aria-hidden="true" />

            <Link
                :href="pageRoutes.show(page.id).url"
                :component="isCurrent ? undefined : 'pages/Show'"
                :prefetch="isCurrent ? false : 'click'"
                class="flex min-w-0 flex-1 items-center gap-2 py-1.5 focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
            >
                <FileText
                    class="size-4 shrink-0"
                    :class="
                        isCurrent ? 'text-foreground' : 'text-muted-foreground'
                    "
                    aria-hidden="true"
                />

                <span
                    class="min-w-0 flex-1 truncate"
                    :class="
                        isCurrent
                            ? 'font-medium text-foreground'
                            : 'text-foreground'
                    "
                    >{{ page.title }}</span
                >

                <span
                    v-if="page.excerpt"
                    class="hidden min-w-0 flex-[2] truncate text-muted-foreground lg:block"
                    >{{ page.excerpt }}</span
                >
            </Link>

            <div
                class="flex shrink-0 items-center transition-opacity group-focus-within:opacity-100 group-hover:opacity-100"
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
                        <DropdownMenuItem
                            v-if="can.updatePage"
                            @select="emit('rename', page)"
                        >
                            Rename
                        </DropdownMenuItem>

                        <template v-if="can.updatePage && canMove">
                            <DropdownMenuSeparator />

                            <DropdownMenuItem
                                v-if="movable.up"
                                @select="
                                    emit('move', { page, direction: 'up' })
                                "
                            >
                                Move up
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="movable.down"
                                @select="
                                    emit('move', { page, direction: 'down' })
                                "
                            >
                                Move down
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="movable.in"
                                @select="
                                    emit('move', { page, direction: 'in' })
                                "
                            >
                                Move inside the page above
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="movable.out"
                                @select="
                                    emit('move', { page, direction: 'out' })
                                "
                            >
                                Move out one level
                            </DropdownMenuItem>
                        </template>

                        <template v-if="can.deletePage">
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                variant="destructive"
                                @select="emit('remove', page)"
                            >
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
