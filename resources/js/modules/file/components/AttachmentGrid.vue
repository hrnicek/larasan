<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { ref, watch } from 'vue';
import AttachmentController from '@/actions/App/Http/Controllers/File/AttachmentController';
import type { TaskAttachment } from '@/modules/task/types';

/**
 * The task's pictures, as pictures.
 *
 * Two things are true of this list that are not true of the rows beside it: it is looked at
 * rather than read, and its order is a decision — the board card draws the *first* image of a
 * task (TASK-250-005), so dragging a photograph to the front is how somebody chooses the cover.
 *
 * Pointer events rather than HTML5 drag and drop, for the reasons `useBoardDragAndDrop` gives:
 * the native API cannot be driven by a synthetic pointer, so a drag could never be honestly
 * demonstrated, and it has no touch support at all.
 *
 * The move is optimistic and rolls back, which is the one place this application allows that
 * (ADR-0013): nothing is destroyed, the tile has to follow the finger to be worth dragging, and
 * a refusal puts the order back exactly as it was.
 */
const props = defineProps<{
    images: TaskAttachment[];
    canReorder: boolean;
}>();

const emit = defineEmits<{ open: [string]; remove: [TaskAttachment] }>();

/** The order being drawn: the server's, except while a drag is rearranging it. */
const order = ref<TaskAttachment[]>([...props.images]);

watch(
    () => props.images,
    (images) => {
        if (dragging.value === null) {
            order.value = [...images];
        }
    },
);

const dragging = ref<string | null>(null);

/*
 * A drag ends with a `click` on the tile it was released over, because that is what a pointer
 * release is. Without this, letting go of a photograph would open it.
 */
const dragged = ref(false);

/** Below this the pointer was a click on a tile, not a drag of it. */
const THRESHOLD = 4;

let origin: { x: number; y: number } | null = null;
let rollback: TaskAttachment[] = [];
let candidate: string | null = null;

const idUnderPointer = (event: PointerEvent): string | null => {
    const element = document.elementFromPoint(event.clientX, event.clientY);
    const tile = element?.closest<HTMLElement>('[data-attachment-tile]');

    return tile?.dataset.attachmentTile ?? null;
};

const pickUp = (event: PointerEvent, image: TaskAttachment): void => {
    if (! props.canReorder || event.button !== 0) {
        return;
    }

    candidate = image.id;
    origin = { x: event.clientX, y: event.clientY };
    rollback = [...order.value];

    window.addEventListener('pointermove', onPointerMove);
    window.addEventListener('pointerup', onPointerUp);
};

const onPointerMove = (event: PointerEvent): void => {
    if (origin === null || candidate === null) {
        return;
    }

    if (dragging.value === null) {
        const far = Math.abs(event.clientX - origin.x) > THRESHOLD || Math.abs(event.clientY - origin.y) > THRESHOLD;

        if (! far) {
            return;
        }

        dragging.value = candidate;
    }

    const overId = idUnderPointer(event);

    if (overId === null || overId === dragging.value) {
        return;
    }

    const from = order.value.findIndex((image) => image.id === dragging.value);
    const to = order.value.findIndex((image) => image.id === overId);

    if (from < 0 || to < 0) {
        return;
    }

    const next = [...order.value];
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);

    order.value = next;
};

const onPointerUp = (): void => {
    window.removeEventListener('pointermove', onPointerMove);
    window.removeEventListener('pointerup', onPointerUp);

    const moved = dragging.value;

    dragging.value = null;
    candidate = null;
    origin = null;

    if (moved === null) {
        return;
    }

    dragged.value = true;
    window.setTimeout(() => (dragged.value = false));

    const index = order.value.findIndex((image) => image.id === moved);
    const wasIndex = rollback.findIndex((image) => image.id === moved);

    if (index < 0 || index === wasIndex) {
        return;
    }

    /*
     * An anchor, never a position (ADR-0009). The tile this one now sits behind is what the
     * server is told; an index would be a number computed from a screen that may be seconds out
     * of date, and two people rearranging at once is exactly when it would be wrong.
     */
    const after = index === 0 ? null : order.value[index - 1].id;
    const restore = [...rollback];

    router.put(
        AttachmentController.move.url(moved),
        { after },
        {
            preserveScroll: true,
            onError: () => (order.value = restore),
        },
    );
};
</script>

<template>
    <ul class="grid grid-cols-[repeat(auto-fill,minmax(7rem,1fr))] gap-2">
        <li
            v-for="image in order"
            :key="image.id"
            :data-attachment-tile="image.id"
            class="group/tile relative"
        >
            <button
                type="button"
                class="block w-full cursor-pointer overflow-hidden rounded-md border border-border bg-muted transition-opacity focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                :class="[
                    dragging === image.id ? 'opacity-40' : '',
                    canReorder ? 'touch-none' : '',
                ]"
                :aria-label="`Open ${image.name}`"
                @pointerdown="pickUp($event, image)"
                @click="! dragged && emit('open', image.id)"
            >
                <!-- The thumbnail, not the original: a grid of twenty photographs would otherwise
                     be twenty full-size downloads. The aspect ratio is fixed rather than the
                     picture's own, so the block below does not move as the tiles arrive. -->
                <img
                    :src="AttachmentController.preview.url(image.id, { query: { size: 'thumb' } })"
                    :alt="image.name"
                    loading="lazy"
                    decoding="async"
                    class="aspect-[4/3] w-full object-cover"
                    draggable="false"
                />
            </button>

            <button
                v-if="image.canDelete"
                type="button"
                class="absolute top-1 right-1 inline-flex size-6 items-center justify-center rounded-md bg-background/85 text-muted-foreground shadow-sm backdrop-blur transition-colors hover:bg-background hover:text-foreground focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none md:opacity-0 md:group-hover/tile:opacity-100"
                :aria-label="`Remove ${image.name}`"
                @click.stop="emit('remove', image)"
            >
                <X class="size-3.5" />
            </button>
        </li>
    </ul>
</template>
