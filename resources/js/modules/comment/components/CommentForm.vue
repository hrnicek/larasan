<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import CommentController from '@/actions/App/Http/Controllers/Comment/CommentController';
import { Button } from '@/components/ui/button';
import UserAvatar from '@/components/UserAvatar.vue';

/**
 * Saying something about a task.
 *
 * One line until it is being written in, because the composer sits in view the whole time the
 * thread is read and a three-row box that is usually empty spends the panel's height on nothing.
 *
 * The rule that matters is the failure one, the same one `TaskTextField` established: **a failed
 * send never discards what was typed.** `useForm` keeps the data, so the text is still there to
 * try again with.
 */
const props = defineProps<{
    taskId: string;
    /** The face beside the box: whoever is about to speak. */
    viewer: { name: string; avatar: string | null } | null;
}>();

const form = useForm({ body: '' });
const writing = ref(false);

const send = (): void => {
    if (form.processing || form.body.trim() === '') {
        return;
    }

    form.post(CommentController.store.url(props.taskId), {
        preserveScroll: true,
        // Only on success: a reset after a failure would throw away the paragraph the network
        // blinked on.
        onSuccess: () => form.reset('body'),
    });
};

/** Collapsing on blur would take the box away from somebody who left to copy something. */
const onBlur = (): void => {
    if (form.body.trim() === '') {
        writing.value = false;
    }
};
</script>

<template>
    <form class="flex items-start gap-2" @submit.prevent="send">
        <UserAvatar v-if="viewer" :user="viewer" size="sm" class="mt-1" />

        <div
            class="min-w-0 flex-1 rounded-lg border border-input bg-background transition-colors focus-within:border-ring"
        >
            <textarea
                v-model="form.body"
                :rows="writing ? 3 : 1"
                :disabled="form.processing"
                placeholder="Add a comment"
                class="w-full resize-none rounded-lg bg-transparent px-3 py-2 text-sm outline-none disabled:opacity-70"
                @focus="writing = true"
                @blur="onBlur"
                @keydown.enter.meta.prevent="send"
                @keydown.enter.ctrl.prevent="send"
            />

            <div v-if="writing" class="flex items-center justify-between gap-2 px-3 pb-2">
                <p v-if="form.errors.body" class="text-xs text-destructive">{{ form.errors.body }}</p>
                <p v-else class="text-xs text-muted-foreground">⌘/Ctrl + Enter to send</p>

                <Button
                    type="submit"
                    size="sm"
                    class="h-7 text-xs"
                    :disabled="form.processing || form.body.trim() === ''"
                >
                    Send
                </Button>
            </div>
        </div>
    </form>
</template>
