<script setup lang="ts">
import { ChevronLeft, ChevronRight, Download } from '@lucide/vue';
import { computed, onBeforeUnmount, watch } from 'vue';
import AttachmentController from '@/actions/App/Http/Controllers/File/AttachmentController';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { formatFileSize } from '@/lib/fileSize';
import type { TaskAttachment } from '@/modules/task/types';

/**
 * One picture, large, with the rest of the task's pictures a key away.
 *
 * Not a routed modal (ADR-0013). What is open here is a way of looking at the page behind it
 * rather than a place on it: closing leaves nothing behind, and an address that reopened a
 * particular photograph would be a second thing to keep in step with an order somebody can
 * change.
 *
 * The full image is the original — the derivative exists to keep a grid cheap, and this is the
 * one moment somebody is actually looking closely.
 */
const props = defineProps<{
    images: TaskAttachment[];
    openId: string | null;
}>();

const emit = defineEmits<{ 'update:openId': [string | null] }>();

const index = computed((): number => props.images.findIndex((image) => image.id === props.openId));
const current = computed((): TaskAttachment | null => props.images[index.value] ?? null);

const step = (by: number): void => {
    if (props.images.length < 2 || index.value < 0) {
        return;
    }

    // Wraps, because a gallery that stops at the end makes somebody drag the pointer back to a
    // control they have just walked away from.
    const next = (index.value + by + props.images.length) % props.images.length;

    emit('update:openId', props.images[next].id);
};

const onKeydown = (event: KeyboardEvent): void => {
    if (event.key === 'ArrowRight') {
        step(1);
    }

    if (event.key === 'ArrowLeft') {
        step(-1);
    }
};

/*
 * On the window rather than on the dialog: the arrows have to work wherever focus landed, and
 * reka-ui puts that on the close button. Escape is the dialog's own and is not touched here.
 */
watch(
    () => props.openId,
    (openId) => {
        if (openId === null) {
            window.removeEventListener('keydown', onKeydown);

            return;
        }

        window.addEventListener('keydown', onKeydown);
    },
    { immediate: true },
);

onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Dialog :open="current !== null" @update:open="(next) => !next && emit('update:openId', null)">
        <DialogContent
            v-if="current"
            class="gap-3 p-4 sm:w-fit sm:max-w-[min(95vw,72rem)] sm:p-6"
        >
            <div class="flex min-w-0 items-center gap-3">
                <div class="min-w-0">
                    <DialogTitle class="truncate text-sm font-medium">{{ current.name }}</DialogTitle>
                    <DialogDescription class="text-xs text-muted-foreground">
                        {{ formatFileSize(current.size) }}
                        <template v-if="images.length > 1"> · {{ index + 1 }} of {{ images.length }}</template>
                    </DialogDescription>
                </div>

                <a
                    :href="AttachmentController.download.url(current.id)"
                    class="ml-auto inline-flex size-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                    :aria-label="`Download ${current.name}`"
                >
                    <Download class="size-4" />
                </a>
            </div>

            <div class="relative flex min-h-0 items-center justify-center">
                <!-- The shape is reserved from what the server measured, so the dialog does not
                     resize under the pointer when the bytes arrive. -->
                <img
                    :key="current.id"
                    :src="AttachmentController.preview.url(current.id)"
                    :alt="current.name"
                    :style="current.image ? { aspectRatio: `${current.image.width} / ${current.image.height}` } : undefined"
                    class="max-h-[70vh] w-auto max-w-full rounded-md object-contain"
                />

                <template v-if="images.length > 1">
                    <button
                        type="button"
                        class="absolute left-1 inline-flex size-9 items-center justify-center rounded-full bg-background/80 text-foreground shadow-sm backdrop-blur transition-colors hover:bg-background focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                        aria-label="Previous image"
                        @click="step(-1)"
                    >
                        <ChevronLeft class="size-5" />
                    </button>

                    <button
                        type="button"
                        class="absolute right-1 inline-flex size-9 items-center justify-center rounded-full bg-background/80 text-foreground shadow-sm backdrop-blur transition-colors hover:bg-background focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                        aria-label="Next image"
                        @click="step(1)"
                    >
                        <ChevronRight class="size-5" />
                    </button>
                </template>
            </div>
        </DialogContent>
    </Dialog>
</template>
