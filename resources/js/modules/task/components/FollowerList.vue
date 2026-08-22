<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import TaskFollowerController from '@/actions/App/Http/Controllers/Task/TaskFollowerController';
import type { TaskAssignee } from '@/modules/task/types';

/**
 * Who is watching this task, and whether the reader is one of them.
 *
 * The server says which way the control points (`following`), because it already compared the
 * ids and a client comparing them again is a second answer to the same question.
 */
const props = defineProps<{
    taskId: string;
    followers: TaskAssignee[];
    following: boolean;
}>();

const working = ref(false);

const toggle = (): void => {
    working.value = true;

    const options = {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            working.value = false;
        },
    };

    if (props.following) {
        router.delete(TaskFollowerController.destroy.url(props.taskId), options);

        return;
    }

    router.post(TaskFollowerController.store.url(props.taskId), {}, options);
};
</script>

<template>
    <section>
        <h3 class="mb-1 text-xs text-muted-foreground">Followers</h3>

        <ul v-if="followers.length" class="flex flex-wrap gap-2 text-sm">
            <li v-for="follower in followers" :key="follower.id" class="rounded bg-muted px-2 py-0.5 text-xs">
                {{ follower.name }}
            </li>
        </ul>

        <p v-else class="text-sm text-muted-foreground">Nobody is watching this task.</p>

        <button
            type="button"
            class="mt-2 rounded border px-2 py-1 text-xs hover:bg-accent disabled:opacity-50"
            :disabled="working"
            :aria-pressed="following"
            @click="toggle"
        >
            {{ following ? 'Stop watching' : 'Watch' }}
        </button>
    </section>
</template>
