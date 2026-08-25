<script setup lang="ts">
import { Deferred } from '@inertiajs/vue3';
import { ArrowDownUp } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Skeleton } from '@/components/ui/skeleton';
import UserAvatar from '@/components/UserAvatar.vue';
import { formatFeedTime, fullFeedTime } from '@/lib/feedTime';
import CommentForm from '@/modules/comment/components/CommentForm.vue';
import CommentLine from '@/modules/comment/components/CommentLine.vue';
import type { TaskFeed, TaskFeedEntry } from '@/modules/task/types';

/**
 * What has happened to this task, and what people have said about it.
 *
 * On its own surface at the foot of the panel, because it is a conversation rather than another
 * field: the fields above are what the task *is*, and this is what has been said about it. The
 * composer stays in view while the thread scrolls under it — a reply control you have to scroll
 * to find is one people stop using.
 *
 * The **only** deferred region in the application, and the reason the list view in Phase 080
 * deliberately was not one: activity and comments are secondary, they can be slow, and the fields
 * above them are worth reading before they arrive.
 */
const props = defineProps<{
    taskId: string;
    feed?: TaskFeed;
    canComment: boolean;
    /** The composer's own face, so the reply box says who is about to speak. */
    viewer: { name: string; avatar: string | null } | null;
}>();

/**
 * Two readings of the same thread. Most of the time somebody wants what was *said*; the whole
 * record is a second question, and answering both in one list makes the first one hard to read.
 */
const tab = ref<'comments' | 'activity'>('comments');

/** The server sends the newest first, because that is the page a long thread needs. A thread is
 *  read downwards, so the order is decided here — in the component that draws it, rather than in
 *  the query that has to paginate it. */
const oldestFirst = ref(true);

const lines = computed<TaskFeedEntry[]>(() => {
    const entries = props.feed?.entries ?? [];
    const shown = tab.value === 'comments' ? entries.filter((entry) => entry.kind === 'comment') : entries;

    return oldestFirst.value ? [...shown].reverse() : [...shown];
});

/**
 * An activity says what happened, in words, from the ids the row kept — never from a snapshot of
 * names that have since changed.
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

const tabs: { id: 'comments' | 'activity'; label: string }[] = [
    { id: 'comments', label: 'Comments' },
    { id: 'activity', label: 'All activity' },
];
</script>

<template>
    <section class="flex flex-col border-t border-border bg-muted/40">
        <div class="flex items-end gap-1 border-b border-border px-4 md:px-6">
            <button
                v-for="option in tabs"
                :key="option.id"
                type="button"
                class="-mb-px shrink-0 border-b-2 px-3 pt-2 pb-2.5 text-sm font-medium whitespace-nowrap transition-colors focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                :class="
                    option.id === tab
                        ? 'border-primary text-foreground'
                        : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground'
                "
                :aria-current="option.id === tab ? 'true' : undefined"
                @click="tab = option.id"
            >
                {{ option.label }}
            </button>

            <button
                type="button"
                class="mb-1.5 ml-auto inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                :aria-label="oldestFirst ? 'Showing oldest first. Show newest first' : 'Showing newest first. Show oldest first'"
                @click="oldestFirst = !oldestFirst"
            >
                <ArrowDownUp class="size-3.5" aria-hidden="true" />
                {{ oldestFirst ? 'Oldest' : 'Newest' }}
            </button>
        </div>

        <div class="px-4 py-4 md:px-6">
            <Deferred data="activity">
                <template #fallback>
                    <div class="flex flex-col gap-2" aria-hidden="true">
                        <Skeleton class="h-4 w-3/4 animate-pulse" />
                        <Skeleton class="h-4 w-1/2 animate-pulse" />
                        <Skeleton class="h-4 w-2/3 animate-pulse" />
                    </div>
                </template>

                <ul v-if="lines.length" class="flex flex-col gap-4">
                    <template v-for="entry in lines" :key="entry.id">
                        <CommentLine v-if="entry.kind === 'comment'" :entry="entry" />

                        <li v-else class="flex items-center gap-2 text-sm text-muted-foreground">
                            <UserAvatar
                                v-if="entry.actor"
                                :user="{ name: entry.actor.name, avatar: entry.actor.avatar }"
                                size="sm"
                            />
                            <span class="min-w-0">
                                <span class="font-medium text-foreground">{{ entry.actor?.name ?? 'Someone' }}</span>
                                {{ describe(entry) }} ·
                                <time class="text-xs" :title="fullFeedTime(entry.createdAt)">
                                    {{ formatFeedTime(entry.createdAt) }}
                                </time>
                            </span>
                        </li>
                    </template>
                </ul>

                <p v-else class="text-sm text-muted-foreground">
                    {{ tab === 'comments' ? 'Nobody has said anything yet.' : 'Nothing has happened yet.' }}
                </p>
            </Deferred>
        </div>

        <!--
            Pinned to the foot of whatever is scrolling — the panel's body or the page itself —
            rather than sitting at the end of a thread somebody has to reach first.
        -->
        <div
            v-if="canComment"
            class="sticky bottom-0 border-t border-border bg-background/95 px-4 py-3 backdrop-blur md:px-6"
        >
            <CommentForm :task-id="taskId" :viewer="viewer" />
        </div>
    </section>
</template>
