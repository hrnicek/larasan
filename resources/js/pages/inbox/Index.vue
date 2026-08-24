<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { BellOff, CheckCheck } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InboxController from '@/actions/App/Http/Controllers/Notification/InboxController';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import type { InboxNotification } from '@/modules/notification/types';
import TaskDetailPanel from '@/modules/task/components/TaskDetailPanel.vue';
import { useTaskPanel } from '@/modules/task/composables/useTaskPanel';
import type { TaskAssignee, TaskDetail, TaskFeed } from '@/modules/task/types';

/**
 * What is waiting for this person, here.
 *
 * Each line is a sentence built from the ids the notification kept and resolved by the server —
 * never from a snapshot, so a task renamed since is named as it is now.
 */
const props = defineProps<{
    notifications: InboxNotification[];
    meta: { page: number; perPage: number; total: number; hasMore: boolean; unread: number };
    /** The panel, when the URL says one is open. `null` rather than absent (TASK-200-004). */
    taskDetail?: TaskDetail | null;
    /** Deferred with the panel: absent until the follow-up request lands. */
    activity?: TaskFeed;
    members: TaskAssignee[];
    priorities: string[];
}>();

const rows = ref<InboxNotification[]>([...props.notifications]);
const loading = ref(false);

watch(() => props.notifications, (notifications) => {
    rows.value = props.meta.page === 1 ? [...notifications] : [...rows.value, ...notifications];
});

const unread = computed(() => props.meta.unread);

/** What happened, in words. The subject's name is whatever it is called now. */
const sentence = (notification: InboxNotification): string => {
    const who = notification.actor?.name ?? 'Somebody';
    const what = notification.subject?.title ?? 'something that has since been removed';

    switch (notification.type) {
        case 'task.assigned':
            return `${who} assigned you ${what}`;
        case 'comment.posted':
            return `${who} commented on ${what}`;
        default:
            return `${who} did something about ${what}`;
    }
};

const { open: openTask, close: closeTask } = useTaskPanel();

/**
 * Clicking a line marks it read and opens what it is about. Read state is the server's answer,
 * so the row is not ticked off locally — the visit that follows re-renders it from what came
 * back.
 *
 * The subject opens as a panel over the Inbox rather than as its own page: somebody working
 * through a list of notifications is working through a list, and reading one should not cost
 * them their place in it. Reachability is still the server's `url` — null where this reader can
 * no longer follow it — and the client never decides that for itself.
 */
const openNotification = (notification: InboxNotification): void => {
    const subject = notification.subject;
    const reachable = subject !== null && subject.url !== null;

    const show = (): void => {
        if (!reachable) {
            return;
        }

        openTask(subject.id);
    };

    if (!notification.read) {
        router.put(InboxController.read.url(notification.id), {}, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: show,
        });

        return;
    }

    show();
};

const markAllRead = (): void => {
    router.put(InboxController.readAll.url(), {}, { preserveScroll: true });
};

const loadMore = (): void => {
    if (!props.meta.hasMore || loading.value) {
        return;
    }

    loading.value = true;

    router.get(
        InboxController.index.url({ query: { page: props.meta.page + 1 } }),
        {},
        {
            only: ['notifications', 'meta'],
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                loading.value = false;
            },
        },
    );
};
</script>

<template>
    <div class="flex h-full flex-1 flex-col">
        <Head title="Inbox" />

        <PageHeader
            title="Inbox"
            :description="unread > 0 ? `${unread} unread` : 'Everything here has been seen'"
        >
            <template v-if="unread > 0" #actions>
                <button
                    type="button"
                    class="inline-flex h-8 items-center gap-1.5 rounded-md border border-border px-2.5 text-[13px] font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                    @click="markAllRead"
                >
                    <CheckCheck class="size-4" />
                    Mark all read
                </button>
            </template>
        </PageHeader>

        <div class="flex flex-1 flex-col gap-4 px-4 py-4 md:px-6">

        <ul v-if="rows.length" class="flex flex-col gap-2">
            <li v-for="notification in rows" :key="notification.id" class="flex items-baseline gap-2 text-sm">
                <span
                    class="mt-1 size-2 shrink-0 rounded-full"
                    :class="notification.read ? 'bg-transparent' : 'bg-primary'"
                    :aria-label="notification.read ? undefined : 'Unread'"
                />

                <!-- A line whose subject is gone, or which this reader can no longer open, is
                     text: a link that leads nowhere is worse than a sentence that explains
                     itself. -->
                <button
                    v-if="notification.subject?.url"
                    type="button"
                    class="text-left underline"
                    @click="openNotification(notification)"
                >
                    {{ sentence(notification) }}
                </button>

                <span v-else>{{ sentence(notification) }}</span>

                <time class="ml-auto text-xs text-muted-foreground">{{ notification.createdAt?.slice(0, 16).replace('T', ' ') }}</time>
            </li>
        </ul>

        <EmptyState
            v-else-if="!loading"
            :icon="BellOff"
            title="You're all caught up"
            description="Comments, assignments and mentions land here. Nothing is waiting."
        />

        <button
            v-if="meta.hasMore"
            type="button"
            class="self-start rounded border border-input px-2 py-1 text-xs"
            :disabled="loading"
            @click="loadMore"
        >
            {{ loading ? 'Loading…' : 'Load more' }}
        </button>

        <TaskDetailPanel
            v-if="taskDetail"
            :key="taskDetail.task.id"
            :detail="taskDetail"
            :members="members"
            :priorities="priorities"
            :activity="activity"
            @close="closeTask"
        />
        </div>
    </div>
</template>
