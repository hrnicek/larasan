<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ChevronUp,
    MoreHorizontal,
    Palette,
    Pencil,
    Plus,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
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

const props = defineProps<{
    projectId: string;
    /** `null` is the ungrouped bucket, which cannot be edited. */
    sectionId: string | null;
    name: string | null;
    color: string | null;
    /** All column ids in display order, ungrouped as `null`; moves are anchor-based. See ADR-0009. */
    siblings: (string | null)[];
    variant: 'board' | 'list';
    can: { create: boolean; update: boolean; delete: boolean };
}>();

const emit = defineEmits<{ rename: [] }>();

const deleting = ref(false);
const working = ref(false);

const order = computed((): string[] =>
    props.siblings.filter((id): id is string => id !== null),
);

const index = computed((): number =>
    props.sectionId === null ? -1 : order.value.indexOf(props.sectionId),
);

const canMoveEarlier = computed((): boolean => index.value > 0);
const canMoveLater = computed(
    (): boolean => index.value >= 0 && index.value < order.value.length - 1,
);

/** `after` is the section this one lands behind; `null` moves it to the front. */
function move(after: string | null): void {
    if (props.sectionId === null) {
        return;
    }

    working.value = true;

    router.put(
        SectionController.move.url(props.sectionId),
        { after },
        { preserveScroll: true, onFinish: () => (working.value = false) },
    );
}

function add(): void {
    working.value = true;

    router.post(
        SectionController.store.url(props.projectId),
        { name: 'New section' },
        { preserveScroll: true, onFinish: () => (working.value = false) },
    );
}

/** The update replaces name and colour together, so the name is sent with every pick. */
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
            // Keeps the colour submenu open between picks.
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
            <DropdownMenuItem
                v-if="can.update && sectionId !== null"
                @select="emit('rename')"
            >
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

            <template
                v-if="can.update && sectionId !== null && order.length > 1"
            >
                <DropdownMenuSeparator />

                <DropdownMenuItem
                    :disabled="working || !canMoveEarlier"
                    @select="move(index >= 2 ? order[index - 2] : null)"
                >
                    <component
                        :is="variant === 'board' ? ChevronLeft : ChevronUp"
                        class="mr-2 size-4 text-muted-foreground"
                    />
                    {{ variant === 'board' ? 'Move left' : 'Move up' }}
                </DropdownMenuItem>

                <DropdownMenuItem
                    :disabled="working || !canMoveLater"
                    @select="move(order[index + 1])"
                >
                    <component
                        :is="variant === 'board' ? ChevronRight : ChevronDown"
                        class="mr-2 size-4 text-muted-foreground"
                    />
                    {{ variant === 'board' ? 'Move right' : 'Move down' }}
                </DropdownMenuItem>

                <DropdownMenuSeparator />
            </template>

            <DropdownMenuItem
                v-if="can.create"
                :disabled="working"
                @select="add"
            >
                <Plus class="mr-2 size-4 text-muted-foreground" />
                Add section
            </DropdownMenuItem>

            <template v-if="can.delete && sectionId !== null">
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    variant="destructive"
                    @select="deleting = true"
                >
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
