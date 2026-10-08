<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import CommentController from '@/actions/App/Http/Controllers/Comment/CommentController';
import { Button } from '@/components/ui/button';
import UserAvatar from '@/components/UserAvatar.vue';
import MentionTextarea from '@/modules/comment/components/MentionTextarea.vue';
import { toStorage } from '@/modules/comment/mentions';
import type { NamedPerson } from '@/modules/comment/mentions';
import type { TaskAssignee } from '@/modules/task/types';

const props = defineProps<{
    taskId: string;
    viewer: { name: string; avatar: string | null } | null;
    /** Offered by `@`; the server decides who may actually be named. */
    people: TaskAssignee[];
}>();

const draft = ref('');
const named = ref<NamedPerson[]>([]);
const form = useForm({ body: '' });
const writing = ref(false);

const send = (): void => {
    if (form.processing || draft.value.trim() === '') {
        return;
    }

    form.body = toStorage(draft.value, named.value);

    form.post(CommentController.store.url(props.taskId), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('body');
            draft.value = '';
            named.value = [];
        },
    });
};

const onBlur = (): void => {
    if (draft.value.trim() === '') {
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
            <MentionTextarea
                v-model="draft"
                v-model:named="named"
                :people="people"
                :rows="writing ? 3 : 1"
                :disabled="form.processing"
                placeholder="Add a comment"
                class="w-full resize-none rounded-lg bg-transparent px-3 py-2 text-sm outline-none disabled:opacity-70"
                @focus="writing = true"
                @blur="onBlur"
                @submit="send"
            />

            <div
                v-if="writing"
                class="flex items-center justify-between gap-2 px-3 pb-2"
            >
                <p v-if="form.errors.body" class="text-xs text-destructive">
                    {{ form.errors.body }}
                </p>
                <p v-else class="text-xs text-muted-foreground">
                    @ to mention · ⌘/Ctrl + Enter to send
                </p>

                <Button
                    type="submit"
                    size="sm"
                    class="h-7 text-xs"
                    :disabled="form.processing || draft.trim() === ''"
                >
                    Send
                </Button>
            </div>
        </div>
    </form>
</template>
