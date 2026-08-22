<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import CommentController from '@/actions/App/Http/Controllers/Comment/CommentController';

/**
 * Saying something about a task.
 *
 * The rule that matters is the failure one, the same one `TaskTextField` established: **a
 * failed send never discards what was typed.** `useForm` keeps the data, so the text is still
 * there to try again with.
 */
const props = defineProps<{ taskId: string }>();

const form = useForm({ body: '' });

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
</script>

<template>
    <form class="flex flex-col gap-1" @submit.prevent="send">
        <textarea
            v-model="form.body"
            rows="3"
            :disabled="form.processing"
            placeholder="Write a comment…"
            class="w-full rounded border border-input bg-transparent px-2 py-1 text-sm disabled:opacity-70"
            @keydown.enter.meta.prevent="send"
            @keydown.enter.ctrl.prevent="send"
        />

        <div class="flex items-center justify-between gap-2">
            <p v-if="form.errors.body" class="text-xs text-destructive">{{ form.errors.body }}</p>
            <p v-else class="text-xs text-muted-foreground">⌘/Ctrl + Enter to send</p>

            <button
                type="submit"
                :disabled="form.processing || form.body.trim() === ''"
                class="rounded bg-primary px-2 py-1 text-xs text-primary-foreground disabled:opacity-50"
            >
                Send
            </button>
        </div>
    </form>
</template>
