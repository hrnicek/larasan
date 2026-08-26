<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Paperclip, Plus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import AttachmentController from '@/actions/App/Http/Controllers/File/AttachmentController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { formatFileSize } from '@/lib/fileSize';
import AttachmentGrid from '@/modules/file/components/AttachmentGrid.vue';
import AttachmentLightbox from '@/modules/file/components/AttachmentLightbox.vue';
import TaskSectionHeading from '@/modules/task/components/TaskSectionHeading.vue';
import type { TaskAttachment } from '@/modules/task/types';

/**
 * What is attached to this task.
 *
 * Two kinds of thing, drawn two ways. A photograph is looked at, so it is a thumbnail that opens
 * (TASK-250-004); a document is read by name, so it stays the row it has always been. Which is
 * which is the server's `kind` (`FileKind`), never a MIME string parsed in this template.
 *
 * Every URL here is an endpoint rather than an address on the disk: a storage path is never a
 * capability (ADR-0007), and the server asks whether this reader may open the task before it
 * sends a byte.
 */
const props = defineProps<{
    taskId: string;
    attachments: TaskAttachment[];
    canAttach: boolean;
    /** Rearranging the files is editing the task, so it is the task's own permission. */
    canReorder: boolean;
}>();

const images = computed((): TaskAttachment[] => props.attachments.filter((file) => file.kind === 'image'));
const documents = computed((): TaskAttachment[] => props.attachments.filter((file) => file.kind !== 'image'));

/** Which photograph is open, if any. Local state: a lightbox is a way of looking, not a place. */
const opened = ref<string | null>(null);

const form = useForm<{ file: File | null }>({ file: null });
const input = ref<HTMLInputElement | null>(null);

const upload = (event: Event): void => {
    const chosen = (event.target as HTMLInputElement).files?.[0] ?? null;

    if (chosen === null) {
        return;
    }

    form.file = chosen;

    form.post(AttachmentController.store.url(props.taskId), {
        preserveScroll: true,
        forceFormData: true,
        // Cleared only on success. A refused upload keeps the choice so the message says what
        // was wrong with *this* file rather than about nothing at all.
        onSuccess: () => {
            form.reset('file');

            if (input.value !== null) {
                input.value.value = '';
            }
        },
    });
};

/*
 * Removing a file is asked about first (ADR-0013). The bytes survive the request — `files:sweep`
 * is the only place they are destroyed — but the person clicking cannot know that, and a file
 * that disappears without a question is a file they will assume is gone.
 */
const removing = ref<TaskAttachment | null>(null);

const remove = (): void => {
    if (removing.value === null) {
        return;
    }

    router.delete(AttachmentController.destroy.url(removing.value.id), {
        preserveScroll: true,
        onFinish: () => (removing.value = null),
    });
};
</script>

<template>
    <section class="flex flex-col gap-1">
        <TaskSectionHeading title="Attachments" :count="attachments.length ? String(attachments.length) : null">
            <!-- The input is the hidden half of the control. A bare file input draws the browser's
                 own text — in the reader's locale, not the application's — beside a button nobody
                 styled; the visible half is the `+` every other block on this screen adds with. -->
            <!-- Only once there is a list to add to. With none, the row below already says
                 *Add a file* in words, and two controls doing one thing is one too many. -->
            <template v-if="canAttach && attachments.length" #add>
                <label
                    class="inline-flex size-7 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-within:ring-2 focus-within:ring-primary-ring"
                    :class="form.processing ? 'pointer-events-none opacity-60' : ''"
                    :title="form.processing ? 'Uploading…' : 'Add a file'"
                >
                    <Plus class="size-4" aria-hidden="true" />
                    <span class="sr-only">{{ form.processing ? 'Uploading…' : 'Add a file' }}</span>

                    <input
                        ref="input"
                        type="file"
                        :disabled="form.processing"
                        class="sr-only"
                        @change="upload"
                    />
                </label>
            </template>
        </TaskSectionHeading>

        <div v-if="attachments.length" class="flex flex-col gap-2">
            <AttachmentGrid
                v-if="images.length"
                :images="images"
                :can-reorder="canReorder"
                @open="(id) => (opened = id)"
                @remove="(image) => (removing = image)"
            />

            <ul v-if="documents.length" class="flex flex-col text-sm">
                <li
                    v-for="attachment in documents"
                    :key="attachment.id"
                    class="group/file flex items-center gap-2 border-b border-border py-2 transition-colors hover:bg-accent/40"
                >
                    <Paperclip class="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />

                    <a
                        :href="AttachmentController.download.url(attachment.id)"
                        class="truncate hover:underline focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                    >
                        {{ attachment.name }}
                    </a>

                    <span class="shrink-0 text-xs text-muted-foreground">{{ formatFileSize(attachment.size) }}</span>
                    <span v-if="attachment.uploader" class="truncate text-xs text-muted-foreground">
                        {{ attachment.uploader.name }}
                    </span>

                    <button
                        v-if="attachment.canDelete"
                        type="button"
                        class="ml-auto inline-flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none md:opacity-0 md:group-hover/file:opacity-100"
                        :aria-label="`Remove ${attachment.name}`"
                        @click="removing = attachment"
                    >
                        <X class="size-4" />
                    </button>
                </li>
            </ul>
        </div>

        <!-- Hidden rather than disabled where somebody may not upload: an affordance that leads
             nowhere is worse than none, and the server refuses either way. -->
        <label
            v-else-if="canAttach"
            class="inline-flex min-h-11 cursor-pointer items-center gap-1.5 rounded-md px-1 text-sm text-muted-foreground transition-colors hover:text-foreground focus-within:ring-2 focus-within:ring-primary-ring md:min-h-8"
            :class="form.processing ? 'pointer-events-none opacity-60' : ''"
        >
            <Plus class="size-4" aria-hidden="true" />
            {{ form.processing ? 'Uploading…' : 'Add a file' }}

            <input type="file" :disabled="form.processing" class="sr-only" @change="upload" />
        </label>

        <p v-else class="text-sm text-muted-foreground">No attachments.</p>

        <p v-if="form.errors.file" class="text-xs text-destructive">{{ form.errors.file }}</p>
        <AttachmentLightbox :images="images" :open-id="opened" @update:open-id="(id) => (opened = id)" />

        <ConfirmDialog
            :open="removing !== null"
            :title="`Remove ${removing?.name}?`"
            description="It comes off this task for everybody. Nothing else on the task changes."
            confirm-label="Remove"
            cancel-label="Keep it"
            @update:open="(next) => !next && (removing = null)"
            @confirm="remove"
        />
    </section>
</template>
