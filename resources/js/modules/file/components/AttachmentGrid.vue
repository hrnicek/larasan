<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { ref, watch } from 'vue';
import AttachmentController from '@/actions/App/Http/Controllers/File/AttachmentController';
import { usePointerDrag } from '@/composables/usePointerDrag';
import type { TaskAttachment } from '@/modules/task/types';

// The first image is the board card's cover, so the order here is meaningful.
const props = defineProps<{
    images: TaskAttachment[];
    canReorder: boolean;
}>();

const emit = defineEmits<{ open: [string]; remove: [TaskAttachment] }>();

const order = ref<TaskAttachment[]>([...props.images]);
const dragging = ref<string | null>(null);

watch(
    () => props.images,
    (images) => {
        if (dragging.value === null) {
            order.value = [...images];
        }
    },
);

const beginDrag = usePointerDrag();

let rollback: TaskAttachment[] = [];

const idAt = (x: number, y: number): string | null => {
    const tile = document
        .elementFromPoint(x, y)
        ?.closest<HTMLElement>('[data-attachment-tile]');

    return tile?.dataset.attachmentTile ?? null;
};

const reorderOver = (x: number, y: number): void => {
    const overId = idAt(x, y);

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

const save = (moved: string): void => {
    const index = order.value.findIndex((image) => image.id === moved);
    const wasIndex = rollback.findIndex((image) => image.id === moved);

    if (index < 0 || index === wasIndex) {
        return;
    }

    // An anchor, never an index. See ADR-0009.
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

const pickUp = (event: PointerEvent, image: TaskAttachment): void => {
    if (!props.canReorder) {
        return;
    }

    beginDrag(event, {
        start: () => {
            rollback = [...order.value];
            dragging.value = image.id;
        },
        move: reorderOver,
        drop: (x, y) => {
            reorderOver(x, y);
            dragging.value = null;
            save(image.id);
        },
        cancel: () => {
            dragging.value = null;
            order.value = rollback;
        },
    });
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
                @click="emit('open', image.id)"
            >
                <img
                    :src="
                        AttachmentController.preview.url(image.id, {
                            query: { size: 'thumb' },
                        })
                    "
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
