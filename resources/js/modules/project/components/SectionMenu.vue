<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { MoreHorizontal, Palette, Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import SectionController from '@/actions/App/Http/Controllers/Section/SectionController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import AccentColorGrid from '@/modules/project/components/AccentColorGrid.vue';

/**
 * What can be done to a column, from the column.
 *
 * These actions existed only on the project's settings screen, which is the wrong place for
 * them: a column is renamed while looking at what is in it, not after navigating away from it.
 * Every one of them is an endpoint that already exists — this is a second door, not a new room.
 */
const props = defineProps<{
    projectId: string;
    /** `null` is the ungrouped bucket, which is a place but not a section: it cannot be edited. */
    sectionId: string | null;
    name: string | null;
    /** The column's own colour, so the palette opens on the one it already has. */
    color: string | null;
    can: { create: boolean; update: boolean; delete: boolean };
}>();

const emit = defineEmits<{ rename: [] }>();

const deleting = ref(false);
const working = ref(false);

/**
 * A new column, at the end.
 *
 * Not "below this one": `CreateSection` appends and `StoreSectionRequest` takes no anchor, so an
 * item promising a position would be lying about where the column lands. Reordering is a
 * separate endpoint and belongs to a separate gesture.
 */
function add(): void {
    working.value = true;

    router.post(
        SectionController.store.url(props.projectId),
        { name: 'New section' },
        { preserveScroll: true, onFinish: () => (working.value = false) },
    );
}

/**
 * Recolouring, which is the same endpoint as renaming.
 *
 * `PUT /sections/{section}` replaces both columns, so the name goes with every pick — the server
 * reads an absent colour as "clear it", deliberately, and a request carrying only the colour would
 * blank the name for the same reason.
 */
function recolor(next: string | null): void {
    if (props.sectionId === null || props.name === null) {
        return;
    }

    working.value = true;

    router.put(
        SectionController.update.url(props.sectionId),
        { name: props.name, color: next },
        {
            preserveScroll: true,
            // The palette stays open, the way the project tile's does: picking a colour and
            // changing your mind about it is one errand.
            preserveState: true,
            onFinish: () => (working.value = false),
        },
    );
}

function remove(): void {
    if (props.sectionId === null) {
        return;
    }

    working.value = true;

    router.delete(SectionController.destroy.url(props.sectionId), {
        preserveScroll: true,
        onFinish: () => {
            working.value = false;
            deleting.value = false;
        },
    });
}
</script>

<template>
    <DropdownMenu v-if="can.create || can.update || can.delete">
        <DropdownMenuTrigger
            class="inline-flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground opacity-0 transition-opacity group-hover/section:opacity-100 hover:bg-accent hover:text-foreground focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none data-[state=open]:opacity-100"
            :aria-label="`Actions for ${name ?? 'No section'}`"
        >
            <MoreHorizontal class="size-4" />
        </DropdownMenuTrigger>

        <DropdownMenuContent align="start" class="w-52">
            <DropdownMenuItem v-if="can.update && sectionId !== null" @select="emit('rename')">
                <Pencil class="mr-2 size-4 text-muted-foreground" />
                Rename section
            </DropdownMenuItem>

            <DropdownMenuSub v-if="can.update && sectionId !== null">
                <DropdownMenuSubTrigger>
                    <Palette class="mr-2 size-4 text-muted-foreground" />
                    Colour
                </DropdownMenuSubTrigger>

                <DropdownMenuSubContent class="w-56 p-3">
                    <AccentColorGrid
                        :model-value="color"
                        :disabled="working"
                        @update:model-value="recolor"
                    />
                </DropdownMenuSubContent>
            </DropdownMenuSub>

            <DropdownMenuItem v-if="can.create" :disabled="working" @select="add">
                <Plus class="mr-2 size-4 text-muted-foreground" />
                Add section
            </DropdownMenuItem>

            <template v-if="can.delete && sectionId !== null">
                <DropdownMenuSeparator />
                <DropdownMenuItem variant="destructive" @select="deleting = true">
                    <Trash2 class="mr-2 size-4" />
                    Delete section
                </DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>

    <ConfirmDialog
        :open="deleting"
        :title="`Delete ${name ?? 'this section'}?`"
        description="The tasks in it are not deleted — they move to the project's ungrouped list and keep everything else about them."
        confirm-label="Delete section"
        cancel-label="Keep it"
        :pending="working"
        @update:open="(next) => (deleting = next)"
        @confirm="remove"
    />
</template>
