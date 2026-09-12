<script setup lang="ts">
import { ChevronLeft, ChevronRight, Download } from '@lucide/vue';
import { computed, onBeforeUnmount, watch } from 'vue';
import AttachmentController from '@/actions/App/Http/Controllers/File/AttachmentController';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { formatFileSize } from '@/lib/fileSize';
import type { TaskAttachment } from '@/modules/task/types';

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

// On the window because reka-ui moves focus to the close button; Escape is left to the dialog.
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

                <!-- `mr-8` leaves room for the dialog's close button. -->
                <Button
                    as="a"
                    variant="outline"
                    size="sm"
                    class="mr-8 ml-auto shrink-0 gap-1.5"
                    :href="AttachmentController.download.url(current.id)"
                    :download="current.name"
                >
                    <Download class="size-4" aria-hidden="true" />
                    Download
                </Button>
            </div>

            <div class="relative flex min-h-0 items-center justify-center">
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
