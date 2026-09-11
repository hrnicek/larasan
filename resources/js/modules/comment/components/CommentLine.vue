<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CommentController from '@/actions/App/Http/Controllers/Comment/CommentController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import UserAvatar from '@/components/UserAvatar.vue';
import { formatFeedTime, fullFeedTime } from '@/lib/feedTime';
import MentionTextarea from '@/modules/comment/components/MentionTextarea.vue';
import { segmentsOf, toDisplay, toStorage } from '@/modules/comment/mentions';
import type { NamedPerson } from '@/modules/comment/mentions';
import type { TaskAssignee, TaskFeedEntry } from '@/modules/task/types';

/**
 * One thing somebody said.
 *
 * A removed comment keeps its place and loses its words: closing the gap would change what the
 * conversation appears to say, and an edited one says so — a thread that silently presents
 * different words leaves everybody who replied answering something nobody can see.
 *
 * A mention is drawn from the body's runs of text, never as markup, and one naming the reader is
 * drawn stronger so they can find where they were asked.
 */
const props = defineProps<{ entry: TaskFeedEntry; people: TaskAssignee[] }>();

const viewerId = computed<number | null>(() => usePage().props.auth.user?.id ?? null);
const segments = computed(() => segmentsOf(props.entry.body ?? ''));

const editing = ref(false);
const draft = ref('');
const named = ref<NamedPerson[]>([]);
const form = useForm({ body: '' });

const startEditing = (): void => {
    const shown = toDisplay(props.entry.body ?? '');

    draft.value = shown.text;
    named.value = shown.named;
    editing.value = true;
};

const save = (): void => {
    if (form.processing || draft.value.trim() === '') {
        return;
    }

    form.body = toStorage(draft.value, named.value);

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
            <MentionTextarea
                v-model="draft"
                v-model:named="named"
                :people="people"
                rows="3"
                :disabled="form.processing"
                class="w-full rounded border border-input bg-transparent px-2 py-1 text-sm disabled:opacity-70"
                @submit="save"
                @cancel="editing = false"
            />

            <p v-if="form.errors.body" class="text-xs text-destructive">{{ form.errors.body }}</p>

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
            <p class="break-words whitespace-pre-line"><template v-for="(segment, index) in segments" :key="index"><span v-if="segment.kind === 'mention'" class="rounded px-0.5 font-medium text-primary" :class="segment.id === viewerId ? 'bg-primary/15' : 'bg-primary/5'">@{{ segment.name }}</span><template v-else>{{ segment.text }}</template></template></p>

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
