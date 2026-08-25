<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import CommentController from '@/actions/App/Http/Controllers/Comment/CommentController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import UserAvatar from '@/components/UserAvatar.vue';
import { formatFeedTime, fullFeedTime } from '@/lib/feedTime';
import type { TaskFeedEntry } from '@/modules/task/types';

/**
 * One thing somebody said.
 *
 * A removed comment keeps its place and loses its words: closing the gap would change what the
 * conversation appears to say, and an edited one says so — a thread that silently presents
 * different words leaves everybody who replied answering something nobody can see.
 */
const props = defineProps<{ entry: TaskFeedEntry }>();

const editing = ref(false);
const form = useForm({ body: props.entry.body ?? '' });

const startEditing = (): void => {
    form.body = props.entry.body ?? '';
    editing.value = true;
};

const save = (): void => {
    if (form.processing || form.body.trim() === '') {
        return;
    }

    form.put(CommentController.update.url(props.entry.id), {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = false;
        },
    });
};

const removing = ref(false);

const remove = (): void => {
    router.delete(CommentController.destroy.url(props.entry.id), {
        preserveScroll: true,
        onFinish: () => (removing.value = false),
    });
};
</script>

<template>
    <li class="group/comment flex gap-2 text-sm">
        <UserAvatar
            v-if="entry.actor"
            :user="{ name: entry.actor.name, avatar: entry.actor.avatar }"
            size="sm"
            class="mt-0.5 shrink-0"
        />

        <div class="flex min-w-0 flex-1 flex-col gap-1">
        <p class="flex items-baseline gap-2">
            <span class="font-medium">{{ entry.actor?.name ?? 'Someone' }}</span>
            <time class="text-xs text-muted-foreground" :title="fullFeedTime(entry.createdAt)">
                {{ formatFeedTime(entry.createdAt) }}
            </time>
            <span v-if="entry.edited" class="text-xs text-muted-foreground">edited</span>
        </p>

        <p v-if="entry.deleted" class="text-muted-foreground italic">Comment removed.</p>

        <template v-else-if="editing">
            <textarea
                v-model="form.body"
                rows="3"
                :disabled="form.processing"
                class="w-full rounded border border-input bg-transparent px-2 py-1 text-sm disabled:opacity-70"
                @keydown.enter.meta.prevent="save"
                @keydown.enter.ctrl.prevent="save"
                @keydown.esc.prevent="editing = false"
            />

            <div class="flex gap-2 text-xs">
                <button
                    type="button"
                    class="min-h-11 underline md:min-h-6"
                    :disabled="form.processing"
                    @click="save"
                >
                    Save
                </button>
                <button type="button" class="min-h-11 text-muted-foreground underline md:min-h-6" @click="editing = false">
                    Cancel
                </button>
            </div>
        </template>

        <template v-else>
            <p class="whitespace-pre-line">{{ entry.body }}</p>

            <div
                v-if="entry.canEdit || entry.canDelete"
                class="flex gap-2 text-xs text-muted-foreground transition-opacity focus-within:opacity-100 md:opacity-0 md:group-hover/comment:opacity-100"
            >
                <button
                    v-if="entry.canEdit"
                    type="button"
                    class="min-h-11 underline md:min-h-6"
                    @click="startEditing"
                >
                    Edit
                </button>
                <button
                    v-if="entry.canDelete"
                    type="button"
                    class="min-h-11 underline md:min-h-6"
                    @click="removing = true"
                >
                    Delete
                </button>
            </div>
        </template>
        </div>

        <ConfirmDialog
            :open="removing"
            title="Delete this comment?"
            description="Its words go; its place in the thread stays, marked as removed, so the conversation still reads in order."
            confirm-label="Delete"
            cancel-label="Keep it"
            @update:open="(next) => (removing = next)"
            @confirm="remove"
        />
    </li>
</template>
