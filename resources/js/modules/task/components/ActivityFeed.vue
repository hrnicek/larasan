<script setup lang="ts">
import { Deferred } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Skeleton } from '@/components/ui/skeleton';
import CommentForm from '@/modules/comment/components/CommentForm.vue';
import CommentLine from '@/modules/comment/components/CommentLine.vue';
import type { TaskFeed, TaskFeedEntry } from '@/modules/task/types';

/**
 * What has happened to this task, and what people have said about it.
 *
 * The **only** deferred region in the application, and the reason the list view in Phase 080
 * deliberately was not one: activity and comments are secondary, they can be slow, and the
 * fields above them are worth reading before they arrive.
 *
 * The server sends the newest lines first, because that is the page a long thread needs. A
 * thread is read downwards, so the page is reversed here — in the component that draws it,
 * rather than in the query that has to paginate it.
 */
const props = defineProps<{
    taskId: string;
    feed?: TaskFeed;
    canComment: boolean;
}>();

const lines = computed<TaskFeedEntry[]>(() => [...(props.feed?.entries ?? [])].reverse());

/**
 * An activity says what happened, in words, from the ids the row kept — never from a snapshot
 * of names that have since changed.
 */
const describe = (entry: TaskFeedEntry): string => {
    const changed = entry.properties?.changed;

    switch (entry.type) {
        case 'task.created':
            return 'created this task';
        case 'task.completed':
            return 'completed this task';
        case 'task.reopened':
            return 'reopened this task';
        case 'task.assigned':
            return entry.properties?.assignee_id === null ? 'unassigned this task' : 'assigned this task';
        case 'task.attached_to_project':
            return 'added this task to a project';
        case 'task.detached_from_project':
            return 'removed this task from a project';
        case 'task.updated':
            return Array.isArray(changed) ? `changed ${changed.join(', ')}` : 'changed this task';
        default:
            return 'did something';
    }
};
</script>

<template>
    <section class="flex flex-col gap-2">
        <h3 class="text-xs text-muted-foreground">Activity</h3>

        <Deferred data="activity">
            <template #fallback>
                <div class="flex flex-col gap-2" aria-hidden="true">
                    <Skeleton class="h-4 w-3/4 animate-pulse" />
                    <Skeleton class="h-4 w-1/2 animate-pulse" />
                    <Skeleton class="h-4 w-2/3 animate-pulse" />
                </div>
            </template>

            <ul v-if="lines.length" class="flex flex-col gap-3">
                <template v-for="entry in lines" :key="entry.id">
                    <CommentLine v-if="entry.kind === 'comment'" :entry="entry" />

                    <li v-else class="text-sm text-muted-foreground">
                        {{ entry.actor?.name ?? 'Someone' }} {{ describe(entry) }}
                        <time class="text-xs">{{ entry.createdAt }}</time>
                    </li>
                </template>
            </ul>

            <p v-else class="text-sm text-muted-foreground">Nothing here yet.</p>
        </Deferred>

        <!-- Hidden rather than disabled: an affordance that never leads anywhere is worse than
             no affordance, and the server refuses the request either way. -->
        <CommentForm v-if="canComment" :task-id="taskId" />
    </section>
</template>
