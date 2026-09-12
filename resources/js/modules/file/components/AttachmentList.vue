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

// Files are served through authorized endpoints, never storage paths. See ADR-0007.
const props = defineProps<{
    taskId: string;
    attachments: TaskAttachment[];
    canAttach: boolean;
    canReorder: boolean;
}>();

const images = computed((): TaskAttachment[] => props.attachments.filter((file) => file.kind === 'image'));
const documents = computed((): TaskAttachment[] => props.attachments.filter((file) => file.kind !== 'image'));

const opened = ref<string | null>(null);

const form = useForm<{ files: File[] }>({ files: [] });
const input = ref<HTMLInputElement | null>(null);

const upload = (event: Event): void => {
    const chosen = Array.from((event.target as HTMLInputElement).files ?? []);

    if (chosen.length === 0) {
        return;
    }

    form.files = chosen;

    form.post(AttachmentController.store.url(props.taskId), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            form.reset('files');

            if (input.value !== null) {
                input.value.value = '';
            }
        },
    });
};

// Errors arrive per file as `files.N`.
const uploadErrors = computed((): string[] =>
    Object.entries(form.errors as Record<string, string | undefined>)
        .filter(([key]) => key === 'files' || key.startsWith('files.'))
        .map(([, message]) => message)
        .filter((message): message is string => typeof message === 'string'),
);

const uploadLabel = computed((): string =>
    form.progress === null || form.progress === undefined ? 'Uploading…' : `Uploading ${form.progress.percentage ?? 0}%…`,
);

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
            <template v-if="canAttach && attachments.length" #add>
                <label
                    class="inline-flex size-7 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-within:ring-2 focus-within:ring-primary-ring"
                    :class="form.processing ? 'pointer-events-none opacity-60' : ''"
                    :title="form.processing ? uploadLabel : 'Add files'"
                >
                    <Plus class="size-4" aria-hidden="true" />
                    <span class="sr-only">{{ form.processing ? uploadLabel : 'Add files' }}</span>

                    <input
                        ref="input"
                        type="file"
                        multiple
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

        <label
            v-else-if="canAttach"
            class="inline-flex min-h-11 cursor-pointer items-center gap-1.5 rounded-md px-1 text-sm text-muted-foreground transition-colors hover:text-foreground focus-within:ring-2 focus-within:ring-primary-ring md:min-h-8"
            :class="form.processing ? 'pointer-events-none opacity-60' : ''"
        >
            <Plus class="size-4" aria-hidden="true" />
            {{ form.processing ? uploadLabel : 'Add files' }}

            <input ref="input" type="file" multiple :disabled="form.processing" class="sr-only" @change="upload" />
        </label>

        <p v-else class="text-sm text-muted-foreground">No attachments.</p>

        <p v-for="message in uploadErrors" :key="message" class="text-xs text-destructive">{{ message }}</p>
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
