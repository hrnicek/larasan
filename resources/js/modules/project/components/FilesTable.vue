<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    File as FileIcon,
    FileArchive,
    FileImage,
    FileMusic,
    FileSpreadsheet,
    FileText,
    FileType,
    Film,
    Paperclip,
    Presentation,
    X,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import AttachmentController from '@/actions/App/Http/Controllers/File/AttachmentController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import UserAvatar from '@/components/UserAvatar.vue';
import { formatFeedTime, fullFeedTime } from '@/lib/feedTime';
import { formatFileSize } from '@/lib/fileSize';
import type { ProjectFile, ProjectFiles } from '@/modules/project/types';

const props = defineProps<{
    files: ProjectFiles;
    loading?: boolean;
}>();

const emit = defineEmits<{ open: [taskId: string] }>();

const navigations = ref(0);
const busy = computed<boolean>(() => props.loading === true || navigations.value > 0);

const tracked = {
    onStart: (): void => {
        navigations.value += 1;
    },
    onFinish: (): void => {
        navigations.value -= 1;
    },
};

const kinds: Record<string, { label: string; icon: Component }> = {
    image: { label: 'Image', icon: FileImage },
    video: { label: 'Video', icon: Film },
    audio: { label: 'Audio', icon: FileMusic },
    pdf: { label: 'PDF', icon: FileText },
    document: { label: 'Document', icon: FileText },
    spreadsheet: { label: 'Spreadsheet', icon: FileSpreadsheet },
    presentation: { label: 'Presentation', icon: Presentation },
    archive: { label: 'Archive', icon: FileArchive },
    text: { label: 'Text', icon: FileType },
    other: { label: 'Other', icon: FileIcon },
};

const kindOf = (file: ProjectFile) => kinds[file.kind] ?? kinds.other;

const columns: { key: string | null; label: string; cell: string }[] = [
    { key: 'name', label: 'File name', cell: 'py-2 pr-3' },
    { key: 'size', label: 'Size', cell: 'px-3 py-2' },
    { key: null, label: 'Attached by', cell: 'px-3 py-2' },
    { key: null, label: 'Type', cell: 'px-3 py-2' },
    { key: null, label: 'Attached to', cell: 'px-3 py-2' },
    { key: 'added', label: 'Added', cell: 'px-3 py-2' },
];

const orderedBy = (key: string | null): boolean =>
    key !== null && props.files.meta.sort === key;

const descending = computed<boolean>(
    () => props.files.meta.direction === 'desc',
);

const ariaSort = (
    key: string | null,
): 'ascending' | 'descending' | 'none' | undefined => {
    if (key === null) {
        return undefined;
    }

    return orderedBy(key)
        ? descending.value
            ? 'descending'
            : 'ascending'
        : 'none';
};

/**
 * Rebuilds the query rather than merging into it: a newly sorted column must send no `direction`,
 * so the server applies that column's default (`ProjectFileSort`).
 */
const orderBy = (key: string | null): void => {
    if (key === null) {
        return;
    }

    const params = new URLSearchParams(window.location.search);

    params.set('sort', key);
    params.delete('page');

    if (orderedBy(key)) {
        params.set('direction', descending.value ? 'asc' : 'desc');
    } else {
        params.delete('direction');
    }

    router.get(
        `${window.location.pathname}?${params.toString()}`,
        {},
        { only: ['files'], preserveScroll: true, preserveState: true, ...tracked },
    );
};

const range = computed<string>(() => {
    const { page, perPage, total } = props.files.meta;
    const first = (page - 1) * perPage + 1;

    return `${first}–${Math.min(page * perPage, total)} of ${total}`;
});

const goTo = (page: number): void => {
    router.reload({ only: ['files'], data: { page }, ...tracked });
};

const removing = ref<ProjectFile | null>(null);

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
    <div class="flex flex-col gap-3 px-4 pt-4 md:px-6">
        <div
            v-if="files.files.length"
            class="[scrollbar-width:thin] [scrollbar-color:var(--color-border)_transparent] overflow-x-auto"
        >
            <table class="w-full min-w-3xl border-collapse text-sm">
                <caption class="sr-only">
                    Files attached to this project's tasks
                </caption>

                <thead>
                    <tr
                        class="border-y border-border text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        <th
                            v-for="column in columns"
                            :key="column.label"
                            scope="col"
                            class="text-left font-semibold"
                            :class="column.cell"
                            :aria-sort="ariaSort(column.key)"
                        >
                            <button
                                v-if="column.key"
                                type="button"
                                class="inline-flex items-center gap-1 uppercase transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                                @click="orderBy(column.key)"
                            >
                                {{ column.label }}
                                <component
                                    :is="descending ? ArrowDown : ArrowUp"
                                    v-if="orderedBy(column.key)"
                                    class="size-3"
                                    aria-hidden="true"
                                />
                            </button>

                            <template v-else>{{ column.label }}</template>
                        </th>

                        <th scope="col" class="w-10 py-2">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>

                <tbody :class="busy ? 'opacity-60' : ''">
                    <tr
                        v-for="file in files.files"
                        :key="file.id"
                        class="group/file border-b border-border transition-colors hover:bg-accent/40"
                    >
                        <th
                            scope="row"
                            class="max-w-md py-2 pr-3 text-left font-normal"
                        >
                            <a
                                :href="
                                    AttachmentController.download.url(file.id)
                                "
                                class="flex items-center gap-2 focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                            >
                                <component
                                    :is="kindOf(file).icon"
                                    class="size-4 shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <span class="truncate hover:underline">{{
                                    file.name
                                }}</span>
                            </a>
                        </th>

                        <td
                            class="px-3 py-2 whitespace-nowrap text-muted-foreground"
                        >
                            {{ formatFileSize(file.size) }}
                        </td>

                        <td class="px-3 py-2">
                            <span
                                v-if="file.uploader"
                                class="flex items-center gap-2"
                            >
                                <UserAvatar :user="file.uploader" size="xs" />
                                <span class="truncate">{{
                                    file.uploader.name
                                }}</span>
                            </span>
                            <span v-else class="text-muted-foreground"
                                >Someone who has left</span
                            >
                        </td>

                        <td class="px-3 py-2 text-muted-foreground">
                            {{ kindOf(file).label }}
                        </td>

                        <td class="max-w-xs px-3 py-2">
                            <button
                                v-if="file.task"
                                type="button"
                                class="block max-w-full truncate text-left hover:underline focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                                @click="emit('open', file.task.id)"
                            >
                                {{ file.task.title }}
                            </button>
                        </td>

                        <td
                            class="px-3 py-2 whitespace-nowrap text-muted-foreground"
                        >
                            <time
                                v-if="file.attachedAt"
                                :datetime="file.attachedAt"
                                :title="fullFeedTime(file.attachedAt)"
                            >
                                {{ formatFeedTime(file.attachedAt) }}
                            </time>
                        </td>

                        <td class="py-2 text-right">
                            <button
                                v-if="file.canDelete"
                                type="button"
                                class="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none md:opacity-0 md:group-hover/file:opacity-100"
                                :aria-label="`Remove ${file.name}`"
                                @click="removing = file"
                            >
                                <X class="size-4" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <EmptyState
            v-else
            :icon="Paperclip"
            title="No files here yet"
            description="Files attached to this project's tasks are collected here. Open a task and add one to its Attachments."
        />

        <nav
            v-if="files.meta.total > files.meta.perPage"
            class="flex items-center gap-2 text-xs"
            aria-label="Pages"
        >
            <button
                type="button"
                class="rounded border border-input px-2 py-1 disabled:opacity-50"
                :disabled="files.meta.page === 1 || busy"
                @click="goTo(files.meta.page - 1)"
            >
                Newer
            </button>

            <button
                type="button"
                class="rounded border border-input px-2 py-1 disabled:opacity-50"
                :disabled="!files.meta.hasMore || busy"
                @click="goTo(files.meta.page + 1)"
            >
                Older
            </button>

            <span
                class="text-muted-foreground"
                role="status"
                aria-live="polite"
                >{{ range }}</span
            >
        </nav>

        <ConfirmDialog
            :open="removing !== null"
            :title="`Remove ${removing?.name}?`"
            description="It comes off the task it is attached to, for everybody. Nothing else on the task changes."
            confirm-label="Remove"
            cancel-label="Keep it"
            @update:open="(next) => !next && (removing = null)"
            @confirm="remove"
        />
    </div>
</template>
